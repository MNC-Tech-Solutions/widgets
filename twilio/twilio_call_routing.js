const express = require('express');
const VoiceResponse = require('twilio').twiml.VoiceResponse;
const https = require('https');
const config = require('./config.json');

const app = express();
app.use(express.urlencoded({ extended: true }));

// Function to get lead owner from GHL
async function getLeadOwner(callerNumber) {
  return new Promise((resolve, reject) => {
    const locationConfig = config.locations[locationId];
    if (!locationConfig || !locationConfig.apiKey) {
      return reject(new Error('Invalid location ID or missing API key in config'));
    }
    const url = `https://rest.gohighlevel.com/v1/contacts/lookup?phone=${encodeURIComponent(callerNumber)}`;
    const req = https.get(url, {
      headers: {
        'Authorization': `Bearer ${locationConfig.apiKey}`,
        'Content-Type': 'application/json'
      },
      timeout: 500
    }, (res) => {
      let data = '';
      res.on('data', (chunk) => { data += chunk; });
      res.on('end', () => {
        try {
          const json = JSON.parse(data);
          const contact = json.contacts?.[0];
          if (!contact) {
            return resolve(null);
          }
          const leadOwnerField = contact.customField?.find(field => field.id === locationConfig.customFieldIds.assignedToNumber);
          const leadOwner = leadOwnerField?.value || null;
          resolve(leadOwner);
        } catch (e) {
          console.error('Error parsing GHL response:', e.message);
          reject(e);
        }
      });
    });

    req.on('error', (error) => {
      console.error('Error querying GHL:', error.message);
      reject(error);
    });

    req.on('timeout', () => {
      req.destroy();
      console.error('GHL API request timed out');
      reject(new Error('GHL API timeout'));
    });
  });
}

app.post('/call_routing', async (req, res) => {
  const twiml = new VoiceResponse();
  const callStatus = req.body.DialCallStatus || 'in-progress';
  const locationId = req.query.locationId;
  const locationConfig = config.locations[locationId];
  const index = parseInt(req.query.index) || 0;
  const callerNumber = req.body.From;

  // Validate locationId
  if (!locationConfig || !locationConfig.phoneNumbers?.length) {
    twiml.say({ voice: 'Polly.Joanna' }, 'Error: Invalid location ID or no phone numbers configured.');
    twiml.hangup();
    res.type('text/xml');
    return res.send(twiml.toString());
  }

  const numbers = locationConfig.phoneNumbers;

  if (callStatus === 'in-progress') {
    let numberToDial;

    if (index === 0) {
      // Initial call: Check for lead owner
      try {
        console.time('getLeadOwner');
        const leadOwner = await getLeadOwner(callerNumber, locationId);
        console.timeEnd('getLeadOwner');

        numberToDial = leadOwner || numbers[0];
      } catch (error) {
        numberToDial = numbers[0];
      }
    } else {
      // Subsequent call: Use next number in list
      numberToDial = numbers[index];
    }

    if (!numberToDial || index >= numbers.length) {
      twiml.say({ voice: 'Polly.Joanna' }, 'Sorry, no one is available to take your call.');
      twiml.hangup();
    } else {
      twiml.say({ voice: 'Polly.Joanna' }, 'Hello, please wait while we connect your call.');
      const dial = twiml.dial({
        timeout: 30,
        action: `/call_routing?locationId=${encodeURIComponent(locationId)}&index=${index + 1}`,
        record: 'record-from-answer'
      });
      dial.number(numberToDial);
    }
  } else {
    if (callStatus === 'completed') {
      twiml.hangup();
    } else if (callStatus === 'busy' || callStatus === 'failed') {
      // First number declined, end call
      twiml.say({ voice: 'Polly.Joanna' }, 'Sorry, the call was declined.');
      twiml.hangup();
    } else if (callStatus === 'no-answer' && index < numbers.length) {
      // First number didn't answer, try next number
      twiml.say({ voice: 'Polly.Joanna' }, 'Hello, please wait while we connect your call.');
      const dial = twiml.dial({
        timeout: 30,
        action: `/call_routing?locationId=${encodeURIComponent(locationId)}&index=${index + 1}`,
        record: 'record-from-answer'
      });
      dial.number(numbers[index]);
    } else {
      twiml.say({ voice: 'Polly.Joanna' }, 'Sorry, no one is available to take your call.');
      twiml.hangup();
    }
  }

  res.type('text/xml');
  res.send(twiml.toString());
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
  console.log(`Server running on port ${PORT}`);
});