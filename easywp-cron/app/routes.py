# -*- coding: utf-8 -*-
from flask import request, url_for
from app import app
import json
import re
import subprocess
import requests

domain_regex = re.compile(r'^([a-zA-Z0-9][a-zA-Z0-9-_]*\.)*[a-zA-Z0-9]*[a-zA-Z0-9-_]*[a-zA-Z0-9]+$')


@app.route('/create', methods=['POST'])
def create():
    global domain_regex
    domain = request.form['domain']
    file = request.form['file']
    if domain and domain_regex.fullmatch(domain):
        # Create an "at" job that will be executed in 2 hours.
        # The command will access http://domain.com/debugger.php?selfDestruct which should remove the file.
        # The output of the webpage will be passed to analyzer/analyze.py.
        # If app.py is running in virtualenv, analyze.py will also run inside this virtualenv
        result = subprocess.run(
            'at now + 2 hours',
            shell=True,
            text=True,
            input='curl -L -X POST -H "Content-Type:application/x-www-form-urlencoded; charset=UTF-8" -d "domain="'+\
            domain +
            ' ' +
            url_for('analyze', _external=True) +
            ' >/dev/null 2>&1'
        )
        if result.returncode == 0:
            success = True
            message = 'Job successfully created.'
        else:
            success = False
            message = 'Job creation failed.'
    elif domain:  # if the domain wasn't validated against the regex
        success = False
        message = 'The domain is invalid.'
    else:  # if no domain was passed
        success = False
        message = 'Please pass a domain.'

    return json.dumps({
        'success': success,
        'message': message,
    })


@app.route('/analyze', methods=['POST'])
def analyze():
    global domain_regex
    domain = request.form['domain']
    if domain and domain_regex.fullmatch(domain):
        response = requests.get(domain + '/wp-admin-debugger.php?')
    else:  # if no domain or the domain wasn't validated against the regex
        success = False
        message = 'The domain is invalid.'


if __name__ == '__main__':
    app.run()
