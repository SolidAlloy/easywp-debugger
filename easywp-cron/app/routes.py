# -*- coding: utf-8 -*-
from datetime import datetime, timedelta

import requests
from app import app, db
from app.email import send_failed_links_email
from app.flock_api import FlockAPI
from app.functions import (add_job, catch_custom_exception, check_inputs,
                           delete_job, find_job, process_failed_inputs)
from app.models import Failed_URL
from flask import jsonify, request, url_for
from requests.exceptions import RequestException, Timeout, TooManyRedirects


@app.route('/create', methods=['POST'])
@catch_custom_exception
def create():
    domain = request.form['domain']
    file = request.form['file']
    validated_inputs = check_inputs({'domain': domain, 'file': file})
    if all(x is True for x in validated_inputs.values()):
        # If a job with this domain is not created yet, create a new one.
        # The new job will access domain.com?selfDesctruct in two hours
        # and will analyze output from it.
        success, message = add_job(domain, file,
                                   url_for('analyze', _external=True))
    else:
        success, message = process_failed_inputs(validated_inputs)

    return jsonify({
        'success': success,
        'message': message,
    })


@app.route('/analyze', methods=['POST'])
@catch_custom_exception
def analyze():
    domain = request.form['domain']
    file = request.form['file']
    validated_inputs = check_inputs({'domain': domain, 'file': file})
    if all(x is True for x in validated_inputs.values()):
        try:
            response = requests.get('http://' + domain + '/' + file,
                                    params={'selfDestruct': '1'})
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
        url_without_query = response.url.split('?')[0]
        if app.config['FAILED_URL_HANDLER'] == 'all' or \
                app.config['FAILED_URL_HANDLER'] == 'email':
            failed_link = Failed_URL(url=url_without_query, error=error, message=message)
            db.session.add(failed_link)
            db.session.commit()
        if app.config['FAILED_URL_HANDLER'] == 'all' or \
                app.config['FAILED_URL_HANDLER'] == 'bot':
            flock_message = "I failed to remove the following debugger file: " +\
                      url_without_query + "<br/>" + message +\
                      "<br/>Please make sure the file is removed."
            FlockAPI.send_message(flock_message, color='#FF0000')

    return jsonify({
        'success': success,
        'error': error,
        'message': message,
    })


@app.route('/delete/<domain>', methods=['DELETE'])
@catch_custom_exception
def delete(domain):
    validated_inputs = check_inputs({'domain': domain})
    if validated_inputs['domain']:
        job_id = find_job(domain)
        if job_id:
            success, message = delete_job(job_id)
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


@app.route('/report-failed-domains', methods=['GET'])
@catch_custom_exception
def report_failed_domains():
    current_time = datetime.utcnow()
    one_day_ago = current_time - timedelta(days=1)
    links_within_one_day = db.session.query(Failed_URL).filter(
        Failed_URL.timestamp > one_day_ago).all()
    if links_within_one_day:
        send_failed_links_email(links_within_one_day)
    return jsonify({'success': True})


@app.route('/delete-old-records', methods=['DELETE'])
@catch_custom_exception
def delete_old_records():
    current_time = datetime.utcnow()
    one_month_ago = current_time - timedelta(days=30)
    links_older_than_month = db.session.query(Failed_URL).filter(
        Failed_URL.timestamp < one_month_ago).all()
    for link in links_older_than_month:
        db.session.delete(link)
    db.session.commit()
    return jsonify({'success': True})


@app.route('/flock-bot', methods=['GET', 'POST'])
@catch_custom_exception
def flock_bot():
    json_request = request.get_json()
    return jsonify(FlockAPI.process(json_request))


if __name__ == '__main__':
    app.run()
