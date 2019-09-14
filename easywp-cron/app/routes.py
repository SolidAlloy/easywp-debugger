# -*- coding: utf-8 -*-
from flask import request, url_for
from app import app, db
from app.models import Failed_URL
from app.email import send_failed_links_email
import json
import re
import subprocess
import requests
from requests.exceptions import Timeout, TooManyRedirects, RequestException
from datetime import datetime, timedelta

domain_regex = re.compile(r'^([a-zA-Z0-9][a-zA-Z0-9-_]*\.)*[a-zA-Z0-9]*[a-zA-Z0-9-_]*[a-zA-Z0-9]+$')
file_regex = re.compile(r'^[a-zA-Z0-9][a-zA-Z0-9-_]{0,30}\.php$')


def check_inputs(values_dict):
    result_dict = {}
    for key in values_dict.keys():
        if key == 'domain':
            if values_dict[key] and domain_regex.fullmatch(values_dict[key]):
                result_dict['domain'] = True
            else:
                result_dict['domain'] = False
        if key == 'file':
            if values_dict[key] and file_regex.fullmatch(values_dict[key]):
                result_dict['file'] = True
            else:
                result_dict['file'] = False
    return result_dict


def get_queue():
    result = subprocess.run('atq', capture_output=True, text=True)  # output current queue
    if result.returncode != 0:
        raise SystemError('atq is broken.')
    return result.stdout


def get_queue_length():
    queue = get_queue()
    if not queue:
        return 0
    lines = queue.split('\n')[:-1]  # split output into lines
    return len(lines)


def find_job(domain):
    queue = get_queue()
    if not queue:
        return False
    lines = queue.split('\n')[:-1]  # split output into lines
    numbers = [line.split('\t', 1)[0] for line in lines]  # get only the job number for each line
    for number in numbers:
        output = subprocess.run(['at', '-c', number],
                                capture_output=True, text=True).stdout
        if output.find(domain):  # get content of each job until the domain is found
            return number
    return False


def add_job(domain, file, anaylyze_url):
    try:
        job_in_queue = find_job(domain)
    except SystemError:
        return [False, '"atq" doesn\'t work on the server.']

    if job_in_queue:
        return [False, 'Job is already created.']
    else:
        if get_queue_length() > 240:
            return [False, 'Resource is temporarily busy.']
        result = subprocess.run(
            ['at', 'now', '+', '2', 'hours'],
            text=True,
            input='curl -L -X POST -H "Content-Type:application/x-www-form-urlencoded; charset=UTF-8" -d "domain=' +
            domain +
            '&file=' +
            file +
            '" ' +
            url_for('analyze', _external=True) +
            ' >/dev/null 2>&1'
        )
        if result.returncode == 0:
            return [True, 'Job successfully created.']
        else:
            return [False, 'Job creation failed.']


def delete_job(job_id):
    result = subprocess.run(['atrm', str(job_id)])
    if result.returncode == 0:
        return [True, 'Job successfully deleted.']
    else:
        return [False, 'There is no such job.']


def process_failed_inputs(validated_inputs):
    if not validated_inputs['domain'] and not validated_inputs['file']:  # if the domain wasn't validated against the regex
        success = False
        message = 'Domain and file are invalid.'
    elif not validated_inputs['domain']:  # if no domain was passed
        success = False
        message = 'The domain is invalid.'
    else:
        success = False
        message = 'The file is invalid.'
    return [success, message]


@app.route('/create', methods=['POST'])
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

    return json.dumps({
        'success': success,
        'message': message,
    })


@app.route('/analyze', methods=['POST'])
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
            message = "The file had already been removed."
        else:
            success = False
            error = str(response.status_code)
            message = "The link returned " + error + " status code."
    else:  # if no domain or the domain wasn't validated against the regex
        success, message = process_failed_inputs(validated_inputs)

    if not success:
        raise NotImplementedError
        failed_link = Failed_URL(url=response.url, error=error, message=message)
        db.session.add(failed_link)
        db.session.commit()

    return json.dumps({
        'success': success,
        'message': message,
    })


@app.route('/delete/<domain>', methods=['DELETE'])
def delete(domain):
    validated_inputs = check_inputs({'domain': domain})
    if validated_inputs['domain']:
        job_id = find_job(domain)
        if job_id:
            success, message = delete_job()
        else:
            success = False
            message = "There is no such job."
    else:
        success = False
        message = 'The domain is invalid.'

    return json.dumps({
        'success': success,
        'message': message,
    })


@app.route('/report-failed-domains', methods=['GET'])
def report_failed_domains():
    current_time = datetime.utcnow()

    one_day_ago = current_time - timedelta(days=1)
    links_within_one_day = db.session.query(Failed_URL).filter(
        Failed_URL.timestamp > one_day_ago).all()
    send_failed_links_email(links_within_one_day)
    db.session.commit()
    return json.dumps({'success': True})


@app.route('/delete-old-records', methods=['DELETE'])
def delete_old_records():
    current_time = datetime.utcnow()
    one_month_ago = current_time - timedelta(days=30)
    links_older_than_month = db.session.query(Failed_URL).filter(
        Failed_URL.timestamp < one_month_ago).all()
    for link in links_older_than_month:
        db.session.delete(link)
    db.session.commit()
    return json.dumps({'success': True})


if __name__ == '__main__':
    app.run()
