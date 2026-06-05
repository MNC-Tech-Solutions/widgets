// Twilio Function: call_routing
// script to handle incoming calls, route based on lead ownership or round-robin, and manage call flow with TwiML

const VoiceResponse = require('twilio').twiml.VoiceResponse;
const https = require('https');

// Persistent rotation state across calls
let ROTATION_STATE = {
  lastIndex: 0
};

exports.handler = async function(context, event, callback) {
  const twiml = new VoiceResponse();
  const callStatus = event.DialCallStatus || 'in-progress';
  const locationId = 'UGB2AOTkcprwX9HHzDh9';
  const index = parseInt(event.index) || 0;
  const callerNumber = event.From || 'unknown';
  const storedLeadOwner = event.leadOwner || null;
  const storedRotationIndex = parseInt(event.rotationIndex) || 0;

  const accessToken = 'pit-3d1a4267-804e-4ac2-a5cb-45b973b2b510';
  const version = '2021-07-28';
 
  const salesNumbers = ['+60132162128', '+60199399153', '+60126630713'];

  let numberToDial;
  let leadOwner = storedLeadOwner;
  let rotationIndex = storedRotationIndex;
  let logMessage = '';

  console.log('--- Raw Event ---');
  console.log(JSON.stringify(event, null, 2));
  console.log('-----------------');

  // End call cleanly if completed
  if (callStatus === 'completed') {
    twiml.hangup();
    return callback(null, twiml);
  }

  // Get lead owner only on first attempt
  if (index === 0 && !leadOwner) {
    try {
      leadOwner = await getLeadOwner(callerNumber, accessToken, version, locationId);
    } catch (error) {
      console.error('Error in getLeadOwner:', error.message);
      leadOwner = null;
    }

    // Initialize rotation
    rotationIndex = ROTATION_STATE.lastIndex;
    ROTATION_STATE.lastIndex = (ROTATION_STATE.lastIndex + 1) % salesNumbers.length;
  }

  const maxAttempts = leadOwner ? 1 : salesNumbers.length;

  if (index >= maxAttempts) {
    console.log(`Max attempts (${maxAttempts}) reached. Hanging up.`);
    twiml.hangup();
    return callback(null, twiml);
  }

  const params = new URLSearchParams({
    locationId,
    index: index + 1,
    leadOwner: leadOwner || '',
    rotationIndex: rotationIndex
  });

  // Greeting logic
  if (index === 0) {
    twiml.say({ voice: 'Polly.Joanna' }, 'Thank you for calling. Connecting you now.');
  } else if (['no-answer', 'busy', 'failed'].includes(callStatus)) {
    twiml.say({ voice: 'Polly.Joanna' }, 'Please wait while we try to connect your call.');
  }

  // Routing logic
  if (leadOwner) {
    numberToDial = leadOwner;
    logMessage = `🔔 Lead has owner: ${leadOwner}`;
  } else {
    const currentIndex = (rotationIndex + index) % salesNumbers.length;
    numberToDial = salesNumbers[currentIndex];
    logMessage = `🔄 Rotator: [${rotationIndex} + ${index}] mod ${salesNumbers.length} = ${currentIndex}`;
  }

  console.log('--- Call Routing ---');
  console.log(`Caller: ${callerNumber}`);
  console.log(`Attempt: ${index + 1}/${maxAttempts}`);
  console.log(`Call Status: ${callStatus}`);
  console.log(`Lead Owner: ${leadOwner || 'None'}`);
  console.log(`Rotation Base: ${rotationIndex}`);
  console.log(logMessage);
  console.log(`Dialing: ${numberToDial}`);
  console.log(`Action URL: /call_routing?${params.toString()}`);
  console.log('--------------------');

  // 🔥 UPDATED DIAL BLOCK
  const dial = twiml.dial({
    callerId: '+60360431765',  // Added callerId
    timeout: 25,               // Changed to 25 seconds
    action: `/call_routing?${params.toString()}`,
    record: 'record-from-answer',
    answerOnBridge: true
  });

  dial.number({}, numberToDial);

  callback(null, twiml);
};


// =========================
// Fetch Owner Phone Number
// =========================
async function getOwnerNumber(userId, accessToken, version) {
  return new Promise((resolve, reject) => {
    const url = `https://services.leadconnectorhq.com/users/${userId}`;

    const req = https.get(url, {
      headers: {
        'Authorization': `Bearer ${accessToken}`,
        'Version': version,
        'Content-Type': 'application/json'
      },
      timeout: 2000
    }, (res) => {
      let data = '';

      res.on('data', (chunk) => { data += chunk; });

      res.on('end', () => {
        try {
          const json = JSON.parse(data);
          const phone =json.phone || null;

          resolve(phone);
        } catch (e) {
          console.error('Error parsing user response:', e.message);
          reject(e);
        }
      });
    });

    req.on('error', (error) => {
      console.error('Error querying user API:', error.message);
      reject(error);
    });

    req.on('timeout', () => {
      req.destroy();
      console.error('User API request timed out');
      reject(new Error('User API timeout'));
    });
  });
}


// =========================
// Fetch Lead Owner (V2)
// =========================
async function getLeadOwner(callerNumber, accessToken, version, locationId) {
  return new Promise((resolve, reject) => {

    const postData = JSON.stringify({
      locationId: locationId,
      pageLimit: 1,
      filters: [
        {
          field: "phone",
          operator: "eq",
          value: callerNumber
        }
      ]
    });

    const options = {
      hostname: 'services.leadconnectorhq.com',
      path: '/contacts/search',
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${accessToken}`,
        'Version': version,
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(postData)
      },
      timeout: 3000
    };

    const req = https.request(options, (res) => {
      let data = '';

      res.on('data', (chunk) => { data += chunk; });

      res.on('end', async () => {
        try {
          const json = JSON.parse(data);
          const contact = json.contacts?.[0];

          if (!contact || !contact.assignedTo) {
            console.log(`No owner found for: ${callerNumber}`);
            return resolve(null);
          }

          const phone = await getOwnerNumber(contact.assignedTo, accessToken, version);
          resolve(phone);

        } catch (e) {
          console.error('Error parsing contact search response:', e.message);
          reject(e);
        }
      });
    });

    req.on('error', (error) => {
      console.error('Error querying search API:', error.message);
      reject(error);
    });

    req.on('timeout', () => {
      req.destroy();
      console.error('Search API request timed out');
      reject(new Error('Search API timeout'));
    });

    req.write(postData);
    req.end();
  });
}