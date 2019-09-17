from app import app, mail
from flask import render_template
from flask_mail import Message


def send_email(subject, recipients, text_body):
    msg = Message(subject, recipients=recipients)
    msg.body = text_body
    mail.send(msg)


def send_failed_links_email(links):
    send_email('Failed Debugger Deletions',
               recipients=[app.config['MAIL_DEFAULT_RECIPIENT']],
               text_body=render_template('email/failed_links.txt',
                                         links=links))
