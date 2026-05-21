FROM python:3.11-slim

WORKDIR /app

COPY migration.py .
COPY .env .

RUN pip install --no-cache-dir supabase python-dotenv

CMD ["python", "-u", "migration.py"]
