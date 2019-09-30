# -*- coding: utf-8 -*-
from datetime import datetime, timedelta

import requests
from app import app, db
from app.email import send_failed_links_email
from app.flock_api import FlockAPI
from app.functions import (catch_custom_exception, check_inputs,
                           process_failed_inputs)
from app.job_manager import JobManager
from app.models import FailedLink
from flask import jsonify, request
from requests.exceptions import RequestException, Timeout, TooManyRedirects


@app.route('/create', methods=['POST'])
@catch_custom_exception
def create():
    """Create a job to remove the debugger file in some time (2 hours
        by default)

    Decorators:
        app.route
        catch_custom_exception

    Returns:
        str -- JSON string containing the success of the job creation
            and additional message.
    """
    domain = request.form['domain']
    file = request.form['file']
    validated_inputs = check_inputs({'domain': domain, 'file': file})
    if all(x is True for x in validated_inputs.values()):
        # If a job with this domain is not created yet, create a new one.
        # The new job will access domain.com?selfDesctruct in two hours
        # and will analyze output from it.
        success, message = JobManager.add_job(domain, file)
    else:
        success, message = process_failed_inputs(validated_inputs)

    return jsonify({
        'success': success,
        'message': message,
    })


@app.route('/analyze', methods=['POST'])
@catch_custom_exception
def analyze():
    """Try removing the debugger file and report the error if it occurs.

    Decorators:
        app.route
        catch_custom_exception

    Returns:
        str -- JSON string containing the success of the file removal,
            short description of the error and an additional message.
    """
    domain = request.form['domain']
    file = request.form['file']
    validated_inputs = check_inputs({'domain': domain, 'file': file})
    error = False
    if all(x is True for x in validated_inputs.values()):
        try:
            response = requests.get('http://' + domain + '/' + file,
                                    params={
                                        'selfDestruct': '1',
                                        'silent': '1',
                                    })
        except Timeout:
            success = False
            error = 'timeout'
            message = "Timeout occurred when accessing the link."
        except TooManyRedirects:
            success = False
            error = 'too many redirects'
            message = "There is a redirection loop at the link."
        except RequestException:
            success = False
            error = 'unknown'
            message = "Unknown exception occurred when trying to access the link."
        if response.status_code == 200:
            if response.json() == {u'success': True}:
                success = True
                message = "The file was removed successfully."
            else:
                success = False
                error = 'no output'
                message = "Link responded with 200 but didn't return any JSON."
        elif response.status_code == 404:
            success = True
            message = "The file has already been removed."
        else:
            success = False
            error = str(response.status_code)
            message = "The link returned " + error + " status code."
    else:  # if no domain or the domain wasn't validated against the regex
        success, message = process_failed_inputs(validated_inputs)

    if not success:
        link_without_query = response.url.split('?')[0]
        if app.config['FAILED_URL_HANDLER'] == 'all' or \
                app.config['FAILED_URL_HANDLER'] == 'email':
            failed_link = FailedLink(link=link_without_query, error=error, message=message)
            db.session.add(failed_link)
            db.session.commit()
        if app.config['FAILED_URL_HANDLER'] == 'all' or \
                app.config['FAILED_URL_HANDLER'] == 'bot':
            flock_message = "I failed to remove the following debugger file: " +\
                      link_without_query + "<br/>" + message +\
                      "<br/>Please make sure the file is removed."
            FlockAPI.send_message(flock_message, color='#FF0000')

    response = {
        'success': success,
        'message': message,
    }
    if error:
        response['error'] = error
    return jsonify(response)


@app.route('/delete/<domain>', methods=['DELETE'])
@catch_custom_exception
def delete(domain):
    """Delete a job from the "atq" queue.

    Decorators:
        app.route
        catch_custom_exception

    Arguments:
        domain {str} -- Domain from which the job creation request was sent.

    Returns:
        str -- JSON string containing the success of the job removal
            and an additional message.
    """
    validated_inputs = check_inputs({'domain': domain})
    if validated_inputs['domain']:
        job_id = JobManager.find_job(domain)
        if job_id:
            success, message = JobManager.delete_job(job_id)
        else:
            success = False
            message = "There is no such job."
    else:
        success = False
        message = 'The domain is invalid.'

    return jsonify({
        'success': success,
        'message': message,
    })


@app.route('/report-failed-links', methods=['GET'])
@catch_custom_exception
def report_failed_links():
    """Report failed links via email.

    Report failed links via email if the email FAILED_URL_HANDLER is
    used. This endpoint is to set up in cron so that it reports the
    failed links regularly.

    Decorators:
        app.route
        catch_custom_exception

    Returns:
        str -- JSON string containing the success of the reporting and
            an additional message.
    """
    if app.config["FAILED_URL_HANDLER"] == 'bot':
        return jsonify({
            'success': False,
            'message': 'Reporting of failed links via email is disabled'
        })
    current_time = datetime.utcnow()
    one_day_ago = current_time - timedelta(days=1)
    links_within_one_day = db.session.query(FailedLink).filter(
        FailedLink.timestamp > one_day_ago).all()
    if links_within_one_day:
        send_failed_links_email(links_within_one_day)
    return jsonify({
        'success': True,
        'message': 'Failed links were sent in an email.'
    })


@app.route('/delete-old-records', methods=['DELETE'])
@catch_custom_exception
def delete_old_records():
    """Delete database records older than month.

    Decorators:
        app.route
        catch_custom_exception

    Returns:
        str -- JSON string containing the success of the deletion.
    """
    current_time = datetime.utcnow()
    one_month_ago = current_time - timedelta(days=30)
    links_older_than_month = db.session.query(FailedLink).filter(
        FailedLink.timestamp < one_month_ago).all()
    for link in links_older_than_month:
        db.session.delete(link)
    db.session.commit()
    return jsonify({'success': True})


@app.route('/flock-bot', methods=['GET', 'POST'])
@catch_custom_exception
def flock_bot():
    """Endpoint for Flock to communicate with the Flock bot.

    Decorators:
        app.route
        catch_custom_exception

    Returns:
        str -- JSON string containing the response of FlockAPI (bot).
    """
    json_request = request.get_json()
    return jsonify(FlockAPI.process(json_request))
