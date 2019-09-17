import re
import subprocess
from functools import wraps

from app import app, cache
from flask import jsonify, url_for

domain_regex = re.compile(r'^([a-zA-Z0-9][a-zA-Z0-9-_]*\.)*[a-zA-Z0-9]*[a-zA-Z0-9-_]*[a-zA-Z0-9]+$')
file_regex = re.compile(r'^[a-zA-Z0-9][a-zA-Z0-9-_]{0,30}\.php$')


def catch_custom_exception(func):
    @wraps(func)
    def decorated_function(*args, **kwargs):
        try:
            return func(*args, **kwargs)
        except:
            app.logger.exception("Exception occurred")
            response = {
                'success': False,
                'message': '500 Internal Server Error'
            }
            return jsonify(response), 500
    return decorated_function


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


@cache.cached(timeout=60, key_prefix='queue_length')
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
