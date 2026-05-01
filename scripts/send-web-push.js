#!/usr/bin/env node

const webpush = require('web-push');

async function readStdin() {
  return new Promise((resolve, reject) => {
    let data = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', (chunk) => {
      data += chunk;
    });
    process.stdin.on('end', () => resolve(data));
    process.stdin.on('error', reject);
  });
}

async function main() {
  const raw = await readStdin();
  const input = JSON.parse(raw || '{}');
  const vapid = input.vapid || {};
  const payload = input.payload || {};
  const subscriptions = Array.isArray(input.subscriptions) ? input.subscriptions : [];

  if (!vapid.publicKey || !vapid.privateKey || !vapid.subject) {
    throw new Error('Missing VAPID configuration');
  }

  webpush.setVapidDetails(vapid.subject, vapid.publicKey, vapid.privateKey);

  const results = [];
  for (const item of subscriptions) {
    const subscription = item && item.subscription ? item.subscription : null;
    const id = item && item.id ? Number(item.id) : 0;
    const endpoint = subscription && subscription.endpoint ? String(subscription.endpoint) : '';

    if (!subscription || !endpoint) {
      results.push({
        id,
        endpoint,
        ok: false,
        statusCode: 0,
        error: 'Invalid subscription payload',
      });
      continue;
    }

    try {
      await webpush.sendNotification(subscription, JSON.stringify(payload), {
        TTL: 60,
        urgency: 'high',
      });
      results.push({
        id,
        endpoint,
        ok: true,
        statusCode: 201,
      });
    } catch (error) {
      results.push({
        id,
        endpoint,
        ok: false,
        statusCode: Number(error && error.statusCode ? error.statusCode : 0),
        error: String((error && (error.body || error.message)) || 'Unknown push error'),
      });
    }
  }

  process.stdout.write(JSON.stringify({ success: true, results }));
}

main().catch((error) => {
  process.stderr.write(String(error && error.stack ? error.stack : error));
  process.exit(1);
});
