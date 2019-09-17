from app import application, db
from app.models import BotUser, Failed_URL


@application.shell_context_processor
def make_shell_context():
    return {
        'db': db,
        'Failed_URL': Failed_URL,
        'BotUser': BotUser
    }
