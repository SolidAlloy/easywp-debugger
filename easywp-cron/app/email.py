from app import app, mail
from flask import render_template
from flask_mail import Message


def send_email(subject, recipients, text_body):
    """Send an email.

    Arguments:
        subject {str} -- Subject of the email
        recipients {list} -- List of recipients
        text_body {str} -- Body of the email
    """
    msg = Message(subject, recipients=recipients)
    msg.body = text_body
    mail.send(msg)


def send_failed_links_email(links):
    """Send an email containing the debugger links that failed within
        the last 24 hours.

    Arguments:
        links {list} -- List of links that failed
    """
    send_email('Failed Debugger Deletions',
               recipients=[app.config['MAIL_DEFAULT_RECIPIENT']],
               text_body=render_template('email/failed_links.txt',
                                         links=links))
