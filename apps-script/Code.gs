/**
 * Tryout Registration — Google Apps Script receiver.
 *
 * Reference copy. The plugin's Settings → Tryout Registration page shows this
 * same script with YOUR shared secret already filled in — copy it from there.
 *
 * Setup:
 *   1. Open your Google Sheet → Extensions → Apps Script.
 *   2. Replace the sample code with this, and set CH_TRYOUT_SECRET to the value
 *      shown on the plugin settings page.
 *   3. Deploy → New deployment → Web app.
 *        Execute as:      Me
 *        Who has access:  Anyone
 *   4. Authorize when prompted, then copy the Web app URL (ends in /exec) into
 *      the plugin settings.
 *
 * The script runs as you (the sheet owner), so it edits the sheet with your
 * own Google account — no Google Cloud project or OAuth client required.
 */

var CH_TRYOUT_SECRET = 'PASTE_THE_SECRET_FROM_PLUGIN_SETTINGS';

function doPost(e) {
  try {
    var data = JSON.parse(e.postData.contents);
    if (String(data.secret) !== CH_TRYOUT_SECRET) {
      return _out({ ok: false, error: 'bad secret' });
    }
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var tab = data.tab || 'Registrations';
    var sheet = ss.getSheetByName(tab) || ss.insertSheet(tab);

    // Write the header row once, when the tab is brand new.
    if (sheet.getLastRow() === 0 && data.headers && data.headers.length) {
      sheet.appendRow(data.headers);
    }
    sheet.appendRow(data.row);
    return _out({ ok: true });
  } catch (err) {
    return _out({ ok: false, error: String(err) });
  }
}

function _out(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}
