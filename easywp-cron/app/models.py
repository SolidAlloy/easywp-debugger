from datetime import datetime

from app import db


class Failed_URL(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    url = db.Column(db.String(128), index=True, unique=True)
    error = db.Column(db.String(32), index=True)
    message = db.Column(db.String(64))
    timestamp = db.Column(db.DateTime, index=True, default=datetime.utcnow)

    def __repr__(self):
        return '<Link {}>'.format(self.url)


class BotUser(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.String(34), index=True, unique=True)
    token = db.Column(db.String(36), index=True, unique=True)

    def __repr__(self):
        return '<BotUser {}>'.format(self.user_id)
