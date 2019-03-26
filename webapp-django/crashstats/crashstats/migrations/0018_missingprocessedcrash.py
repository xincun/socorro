# Generated by Django 2.1.7 on 2019-03-26 18:15

from django.db import migrations, models


class Migration(migrations.Migration):

    dependencies = [
        ('crashstats', '0017_missingprocessedcrashes'),
    ]

    operations = [
        migrations.CreateModel(
            name='MissingProcessedCrash',
            fields=[
                ('id', models.AutoField(auto_created=True, primary_key=True, serialize=False, verbose_name='ID')),
                ('crash_id', models.CharField(help_text='crash id for missing processed crash', max_length=36, unique=True)),
                ('is_processed', models.BooleanField(default=False, help_text='whether this crash was eventually processed')),
                ('created', models.DateTimeField(auto_now_add=True, help_text='date discovered it was missing')),
            ],
        ),
    ]
