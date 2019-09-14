from app import db
from datetime import datetime


class Failed_URL(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    url = db.Column(db.String(128), index=True, unique=True)
    error = db.Column(db.String(32), index=True)
    message = db.Column(db.String(64))
    timestamp = db.Column(db.DateTime, index=True, default=datetime.utcnow)

    def __repr__(self):
        return '<Link {}>'.format(self.url)
