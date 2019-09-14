from app import app, db
from app.models import Failed_URL


@app.shell_context_processor
def make_shell_context():
    return {'db': db, 'Failed_URL': Failed_URL}
