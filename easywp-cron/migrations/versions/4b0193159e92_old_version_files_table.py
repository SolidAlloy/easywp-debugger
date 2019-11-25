"""old_version_files table

Revision ID: 4b0193159e92
Revises: 7922b826e39e
Create Date: 2019-11-25 17:53:04.466443

"""
from alembic import op
import sqlalchemy as sa


# revision identifiers, used by Alembic.
revision = '4b0193159e92'
down_revision = '7922b826e39e'
branch_labels = None
depends_on = None


def upgrade():
    # ### commands auto generated by Alembic - please adjust! ###
    op.create_table('old_version_files',
    sa.Column('id', sa.Integer(), nullable=False),
    sa.Column('link', sa.String(length=128), nullable=True),
    sa.PrimaryKeyConstraint('id')
    )
    op.create_index(op.f('ix_old_version_files_link'), 'old_version_files', ['link'], unique=True)
    # ### end Alembic commands ###


def downgrade():
    # ### commands auto generated by Alembic - please adjust! ###
    op.drop_index(op.f('ix_old_version_files_link'), table_name='old_version_files')
    op.drop_table('old_version_files')
    # ### end Alembic commands ###
