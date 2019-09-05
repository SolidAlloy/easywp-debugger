# -*- coding: utf-8 -*-
from flask import request, url_for
from app import app
import json
import re
import subprocess
import requests
from requests.exceptions import Timeout, TooManyRedirects, RequestException

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


def find_job(domain):
    result = subprocess.run('atq', capture_output=True, text=True)  # output current queue
    if result.returncode != 0:
        raise SystemError('atq is broken.')
    if not result.stdout:
        return False
    lines = result.stdout.split('\n')[:-1]  # split output in lines
    numbers = [line.split('\t', 1)[0] for line in lines]  # get only the job number for each line
    for number in numbers:
        output = subprocess.run(['at', '-c', number],
                                capture_output=True, text=True).stdout
        if output.find(domain):  # get content of each job until the domain is found
            return True
    return False


def add_job(domain, file, anaylyze_url):
    try:
        job_in_queue = find_job(domain)
    except SystemError:
        return [False, '"atq" doesn\'t work on the server.']

    if job_in_queue:
        return [False, 'Job is already created.']
    else:
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


@app.route('/create', methods=['POST'])
def create():
    domain = request.form['domain']
    file = request.form['file']
    validated_inputs = check_inputs({'domain': domain, 'file': file})
    if all(x is True for x in validated_inputs.values()):
        # If a job with this domain is not created yet, create a new one.
        # The new job will access domain.com?selfDesctruct in two hours
        # and will analyze output from it.

        success, message = add_job(domain, file, url_for('analyze', _external=True))

    elif not validated_inputs['domain'] and not validated_inputs['file']:  # if the domain wasn't validated against the regex
        success = False
        message = 'Domain and file are invalid.'
    elif not validated_inputs['domain']:  # if no domain was passed
        success = False
        message = 'The domain is invalid.'
    elif not validated_inputs['file']:
        success = False
        message = 'The file is invalid.'

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
        except TooManyRedirects:
            success = False
            error = 'too many redirects'
        except RequestException:
            success = False
            error = 'unknown'
        if response.status_code == 200:
            if response.json() == {u'success': True}:
                success = True
            else:
                success = False
                error = 'no output'
        elif response.status_code == 404:
            status = True
        else:
            status = False
            error = str(response.status_code)
    else:  # if no domain or the domain wasn't validated against the regex
        success = False
        message = 'The domain is invalid.'


@app.route('/delete/<str:domain>', methods=['DELETE'])
def delete():
    if job_id >= len(cron) or job_id < 0:
        return json.dumps({
            'status': 'fail',
            'message': 'Job ID is invalid.'
        })

    cron.remove(cron[job_id])
    cron.write()

    return json.dumps({
        'status': 'ok',
        'message': 'Job deleted successfully.'
    })


if __name__ == '__main__':
    app.run()
