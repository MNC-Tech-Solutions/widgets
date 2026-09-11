const nodemailer = require('nodemailer');
const config = require('../config');

let transporter = null;

function getTransporter() {
  if (transporter) return transporter;
  if (!config.smtp.host || !config.alertEmailTo) return null;
  transporter = nodemailer.createTransport({
    host: config.smtp.host,
    port: config.smtp.port,
    secure: config.smtp.secure,
    auth: config.smtp.user ? { user: config.smtp.user, pass: config.smtp.pass } : undefined,
  });
  return transporter;
}

async function sendAlert(subject, text) {
  const t = getTransporter();
  if (!t) {
    console.error('[mailer] SMTP not configured, alert not sent:', subject, text);
    return;
  }
  await t.sendMail({
    from: config.alertEmailFrom,
    to: config.alertEmailTo,
    subject,
    text,
  });
}

module.exports = { sendAlert };
