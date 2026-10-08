/**
 * Event Evaluation System -> Google Forms / Drive Bridge
 *
 * SETUP:
 * 1. Create a standalone Apps Script project at https://script.google.com
 * 2. Paste this file into Code.gs.
 * 3. Change WEB_APP_SECRET below to a long random value.
 * 4. Deploy > New deployment > Web app.
 *    Execute as: Me
 *    Who has access: Anyone
 * 5. Authorize Forms, Sheets, and Drive access.
 * 6. Copy the /exec Web App URL into the Evaluation System > Settings.
 * 7. Put the SAME secret in the Evaluation System > Settings.
 */

const WEB_APP_SECRET = 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET';

function doGet() {
  return jsonOutput({
    ok: true,
    service: 'Event Evaluation Google Forms Bridge',
    message: 'Bridge is online. Use POST requests from the Evaluation System.'
  });
}

function doPost(e) {
  try {
    const payload = JSON.parse((e && e.postData && e.postData.contents) || '{}');
    if (!payload.secret || payload.secret !== WEB_APP_SECRET) {
      return jsonOutput({ ok: false, error: 'Unauthorized request. Shared secret does not match.' });
    }

    switch (payload.action) {
      case 'ping':
        return jsonOutput({ ok: true, message: 'Google integration is connected.' });
      case 'create_form':
        return jsonOutput(createEvaluationForm(payload));
      case 'set_form_status':
        return jsonOutput(setFormStatus(payload));
      default:
        return jsonOutput({ ok: false, error: 'Unknown action.' });
    }
  } catch (err) {
    return jsonOutput({
      ok: false,
      error: err && err.message ? err.message : String(err)
    });
  }
}

function createEvaluationForm(payload) {
  const event = payload.event || {};
  const questions = Array.isArray(payload.questions) ? payload.questions : [];
  const participantFields = Array.isArray(payload.participant_fields) ? payload.participant_fields : [];
  if (!event.title) throw new Error('Event title is required.');
  if (!questions.length) throw new Error('No questions were supplied.');

  const organization = payload.organization_name || 'Organization';
  const formTitle = event.title + ' - Evaluation Form';
  const form = FormApp.create(formTitle);

  const description = buildEventDescription(organization, event);
  form.setDescription(description)
      .setConfirmationMessage('Thank you for your valuable feedback. Your response has been recorded.')
      .setProgressBar(true)
      .setShuffleQuestions(false)
      .setShowLinkToRespondAgain(false)
      .setPublishingSummary(false);

  // New Google Forms are published by default. On forms that support advanced
  // publishing, explicitly publish so the public responder link is usable.
  try {
    if (form.supportsAdvancedResponderPermissions()) form.setPublished(true);
  } catch (ignore) {}

  // Keep response acceptance aligned with the source event status at creation.
  form.setAcceptingResponses(String(event.status || '').toLowerCase() === 'open');

  // Participant information is generated from the event's editable field builder.
  // Disable Forms' built-in email collector to avoid duplicating a custom Email field.
  try { form.setCollectEmail(false); } catch (ignore) {}

  addParticipantInformation(form, event, participantFields);

  let lastCategory = null;
  questions.forEach(function(q) {
    const category = String(q.category || 'General Evaluation');
    if (category !== lastCategory) {
      form.addSectionHeaderItem()
          .setTitle(category)
          .setHelpText('Please answer each item based on your actual experience.');
      lastCategory = category;
    }
    addQuestion(form, q);
  });

  // Create the response workbook in Google Drive and link it to the form.
  const ss = SpreadsheetApp.create('[Responses] ' + event.title);
  const info = ss.getSheets()[0];
  info.setName('Event Info');
  info.getRange('A1:B1').setValues([['Field', 'Value']]).setFontWeight('bold');
  const infoRows = [
    ['Event Code', event.code || ''],
    ['Event', event.title || ''],
    ['Type', event.event_type || ''],
    ['Date', event.event_date || ''],
    ['Venue', event.venue || ''],
    ['Organizer', event.organizer || ''],
    ['Generated', new Date()],
    ['Source', 'Event Evaluation and Feedback Management System']
  ];
  info.getRange(2, 1, infoRows.length, 2).setValues(infoRows);
  info.autoResizeColumns(1, 2);

  form.setDestination(FormApp.DestinationType.SPREADSHEET, ss.getId());

  // Optional: move both files to one chosen Google Drive folder.
  const folderId = String(payload.drive_folder_id || '').trim();
  if (folderId) {
    const folder = DriveApp.getFolderById(folderId);
    DriveApp.getFileById(form.getId()).moveTo(folder);
    DriveApp.getFileById(ss.getId()).moveTo(folder);
  }

  return {
    ok: true,
    form_id: form.getId(),
    form_url: form.getPublishedUrl(),
    edit_url: form.getEditUrl(),
    summary_url: form.getSummaryUrl(),
    sheet_id: ss.getId(),
    sheet_url: ss.getUrl(),
    accepting_responses: form.isAcceptingResponses(),
    message: 'Google Form and linked response spreadsheet were created successfully.'
  };
}

function setFormStatus(payload) {
  const formId = String(payload.form_id || '').trim();
  if (!formId) throw new Error('Google Form ID is missing.');
  const form = FormApp.openById(formId);
  const accepting = Boolean(payload.accepting_responses);
  try {
    if (form.supportsAdvancedResponderPermissions()) form.setPublished(true);
  } catch (ignore) {}
  form.setAcceptingResponses(accepting);
  return {
    ok: true,
    accepting_responses: form.isAcceptingResponses(),
    form_url: form.getPublishedUrl(),
    message: accepting ? 'Google Form is now accepting responses.' : 'Google Form is now closed to responses.'
  };
}

function buildEventDescription(organization, event) {
  const lines = [];
  lines.push(organization);
  if (event.event_type) lines.push('Type: ' + event.event_type);
  if (event.theme) lines.push('Theme: ' + event.theme);
  if (event.event_date) lines.push('Date: ' + event.event_date);
  if (event.venue) lines.push('Venue: ' + event.venue);
  if (event.organizer) lines.push('Organizer: ' + event.organizer);
  if (event.speaker) lines.push('Speaker / Trainer / Facilitator: ' + event.speaker);
  if (event.target_participants) lines.push('Target Participants: ' + event.target_participants);
  if (event.description) lines.push('', event.description);
  lines.push('', 'Please answer honestly and objectively. Your feedback will help improve future programs and activities.');
  return lines.join('\n');
}

function addParticipantInformation(form, event, fields) {
  form.addSectionHeaderItem()
      .setTitle('Participant Information')
      .setHelpText(event.allow_anonymous
        ? 'You may leave identifying information blank if you prefer to respond anonymously.'
        : 'Please provide the requested participant information.');

  if (!Array.isArray(fields) || !fields.length) return;

  fields.forEach(function(field) {
    const label = String(field.label || '').trim();
    if (!label) return;
    const type = String(field.type || 'text');
    const options = Array.isArray(field.options) ? field.options.map(String).filter(Boolean) : [];
    // Google Forms cannot conditionally hide fields based on an anonymous checkbox,
    // so identifying fields are made optional whenever anonymous responses are allowed.
    const required = Boolean(field.required) && !(Boolean(event.allow_anonymous) && Boolean(field.hide_when_anonymous));

    if (type === 'textarea') {
      form.addParagraphTextItem().setTitle(label).setRequired(required);
      return;
    }
    if (type === 'select') {
      form.addListItem().setTitle(label).setChoiceValues(options.length ? options : ['Option 1']).setRequired(required);
      return;
    }
    if (type === 'radio') {
      form.addMultipleChoiceItem().setTitle(label).setChoiceValues(options.length ? options : ['Option 1']).setRequired(required);
      return;
    }
    if (type === 'checkbox') {
      form.addCheckboxItem().setTitle(label).setChoiceValues(options.length ? options : ['Option 1']).setRequired(required);
      return;
    }

    const item = form.addTextItem().setTitle(label).setRequired(required);
    try {
      if (type === 'email') {
        item.setValidation(FormApp.createTextValidation().requireTextIsEmail().build());
      } else if (type === 'number') {
        item.setValidation(FormApp.createTextValidation().requireNumber().build());
      }
    } catch (ignore) {}
  });
}

function addQuestion(form, q) {
  const title = String(q.text || '').trim();
  if (!title) return;
  const type = String(q.type || 'likert');
  const required = Boolean(q.required);
  const options = Array.isArray(q.options) ? q.options.map(String).filter(Boolean) : [];

  if (type === 'likert') {
    const style = String(q.likert_style || 'agreement');
    const labels = style === 'quality'
      ? ['5 – Excellent', '4 – Very Good', '3 – Good', '2 – Fair', '1 – Poor']
      : ['5 – Strongly Agree', '4 – Agree', '3 – Neutral', '2 – Disagree', '1 – Strongly Disagree'];
    form.addMultipleChoiceItem().setTitle(title).setChoiceValues(labels).setRequired(required);
    return;
  }

  if (type === 'multiple_choice') {
    const values = options.length ? options : ['Option 1', 'Option 2'];
    form.addMultipleChoiceItem().setTitle(title).setChoiceValues(values).setRequired(required);
    return;
  }

  if (type === 'checkbox') {
    const values = options.length ? options : ['Option 1', 'Option 2'];
    form.addCheckboxItem().setTitle(title).setChoiceValues(values).setRequired(required);
    return;
  }

  if (type === 'yes_no') {
    form.addMultipleChoiceItem().setTitle(title).setChoiceValues(['Yes', 'No']).setRequired(required);
    return;
  }

  if (type === 'dropdown') {
    const values = options.length ? options : ['Option 1', 'Option 2'];
    form.addListItem().setTitle(title).setChoiceValues(values).setRequired(required);
    return;
  }

  if (type === 'paragraph') {
    form.addParagraphTextItem().setTitle(title).setRequired(required);
    return;
  }

  form.addTextItem().setTitle(title).setRequired(required);
}

function jsonOutput(obj) {
  return ContentService
    .createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}
