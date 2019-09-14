from flask_mail import Message
from app import app, mail
from flask import render_template


def send_email(subject, sender, recipients, text_body):
    msg = Message(subject, sender=sender, recipients=recipients)
    msg.body = text_body
    mail.send(msg)


def send_failed_links_email(links):
    send_email('Failed Debugger Deletions',
               recipients=[app.config['DEFAULT_MAIL_RECIPIENT']],
               text_body=render_template('email/failed_links.txt',
                                         links=links))
