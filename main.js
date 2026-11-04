// Elfsteden-log – gemaakt door Richard Hendriksen, PD3RAH – www.pd3rah.nl
// © 2026 Richard Hendriksen, PD3RAH. Alle rechten voorbehouden.
// Gebruik op eigen risico en verantwoordelijkheid.
const { app, BrowserWindow, ipcMain, shell, Menu, dialog, net } = require('electron');
const fs = require('fs');
const path = require('path');

const DIR = () => path.join(app.getPath('documents'), 'Elfsteden-log');
const BAK = () => path.join(DIR(), 'reservekopieen');
const MAIN = () => path.join(DIR(), 'elfsteden-log.json');
const PREV = () => path.join(DIR(), 'elfsteden-log.vorige.json');
const KEEP = 500; // maximaal aantal reservekopieën

// Schrijf veilig: eerst naar .tmp, dan fsync (echt op schijf), dan hernoemen.
function atomicWrite(file, data) {
  const tmp = file + '.tmp';
  const fd = fs.openSync(tmp, 'w');
  try { fs.writeSync(fd, data); fs.fsyncSync(fd); } finally { fs.closeSync(fd); }
  fs.renameSync(tmp, file);
}
const p2 = n => String(n).padStart(2, '0');
function stamp(d) { return d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate()) + '_' + p2(d.getHours()) + p2(d.getMinutes()); }

function saveState(json) {
  JSON.parse(json); // alleen geldige gegevens wegschrijven
  fs.mkdirSync(BAK(), { recursive: true });
  if (fs.existsSync(MAIN())) { try { fs.copyFileSync(MAIN(), PREV()); } catch (e) {} }
  atomicWrite(MAIN(), json);
  // één reservekopie per minuut (zelfde minuut = overschrijven)
  atomicWrite(path.join(BAK(), 'elfsteden-log_' + stamp(new Date()) + '.json'), json);
  try {
    const files = fs.readdirSync(BAK()).filter(f => f.endsWith('.json')).sort();
    files.slice(0, Math.max(0, files.length - KEEP)).forEach(f => fs.unlinkSync(path.join(BAK(), f)));
  } catch (e) {}
  return MAIN();
}
function loadState() {
  for (const f of [MAIN(), PREV()]) {
    try { const j = fs.readFileSync(f, 'utf8'); JSON.parse(j); return { json: j, path: f }; } catch (e) {}
  }
  // laatste redmiddel: nieuwste reservekopie
  try {
    const files = fs.readdirSync(BAK()).filter(f => f.endsWith('.json')).sort().reverse();
    for (const f of files) { try { const j = fs.readFileSync(path.join(BAK(), f), 'utf8'); JSON.parse(j); return { json: j, path: f }; } catch (e) {} }
  } catch (e) {}
  return { json: null, path: MAIN() };
}

// Synchroon: de renderer gaat pas verder als de QSO echt op schijf staat.
ipcMain.on('save', (e, json) => {
  try { e.returnValue = { ok: true, path: saveState(json) }; }
  catch (err) { e.returnValue = { ok: false, error: String(err) }; }
});
ipcMain.on('load', e => { try { e.returnValue = loadState(); } catch (err) { e.returnValue = { json: null }; } });
ipcMain.handle('latestRelease', async () => {
  const r = await net.fetch('https://api.github.com/repos/PD3RAH/elfsteden-log/releases/latest', { headers: { 'Accept': 'application/vnd.github+json', 'User-Agent': 'Elfsteden-log/' + app.getVersion() } });
  if (!r.ok) throw new Error('HTTP ' + r.status);
  const j = await r.json();
  return { tag: j.tag_name, url: j.html_url, name: j.name, date: j.published_at };
});
ipcMain.handle('openFolder', () => { fs.mkdirSync(DIR(), { recursive: true }); return shell.openPath(DIR()); });

let win = null;
if (!app.requestSingleInstanceLock()) { app.quit(); }
else {
  app.on('second-instance', () => { if (win) { if (win.isMinimized()) win.restore(); win.focus(); } });
  app.whenReady().then(() => {
    Menu.setApplicationMenu(Menu.buildFromTemplate([
      { label: 'Bestand', submenu: [
        { label: 'Map met logbestanden openen', click: () => { fs.mkdirSync(DIR(), { recursive: true }); shell.openPath(DIR()); } },
        { label: 'Afdrukken…', accelerator: 'CmdOrCtrl+P', click: () => win && win.webContents.executeJavaScript("document.getElementById('btnPrint').click()") },
        { type: 'separator' }, { role: 'quit', label: 'Afsluiten' } ] },
      { label: 'Beeld', submenu: [ { role: 'zoomIn', label: 'Groter' }, { role: 'zoomOut', label: 'Kleiner' }, { role: 'resetZoom', label: 'Normale grootte' }, { type: 'separator' }, { role: 'togglefullscreen', label: 'Volledig scherm' } ] },
      { label: 'Help', submenu: [
        { label: 'Handleiding', click: () => openHelp() },
        { label: 'Controleren op nieuwe versie', click: () => win && win.webContents.executeJavaScript('window.checkUpdate && window.checkUpdate(true)') },
        { label: 'Enquête invullen…', click: () => shell.openExternal('https://www.pd3rah.nl/elfsteden-enquete.php?v=' + app.getVersion() + '&t=app') },
        { label: 'Website www.pd3rah.nl', click: () => shell.openExternal('https://www.pd3rah.nl') },
        { label: 'Over Elfsteden-log', click: () => dialog.showMessageBox(win, { type: 'info', title: 'Over Elfsteden-log',
          message: 'Elfsteden-log ' + app.getVersion(),
          detail: 'Logprogramma voor de 42e Friese Elfsteden Contest (2026).\nGemaakt door Richard Hendriksen, PD3RAH\nmet behulp van Claude (AI van Anthropic)\nwww.pd3rah.nl\n© 2026 Richard Hendriksen, PD3RAH. Alle rechten voorbehouden.\n\nGebruik op eigen risico en verantwoordelijkheid. De maker aanvaardt geen enkele aansprakelijkheid.\n\nLogbestanden: ' + DIR() }) } ] }
    ]));
    win = new BrowserWindow({ width: 1300, height: 900, title: 'Elfsteden-log', icon: path.join(__dirname, 'icon.png'),
      webPreferences: { preload: path.join(__dirname, 'preload.js'), contextIsolation: true, sandbox: false } });
    win.webContents.setWindowOpenHandler(({ url }) => {
      if (url.endsWith('handleiding.html')) { openHelp(); return { action: 'deny' }; }
      if (/^https?:/.test(url)) shell.openExternal(url);
      return { action: 'deny' };
    });
    win.loadFile(path.join(__dirname, 'elfsteden-log.html'));
  });
  app.on('window-all-closed', () => app.quit());
}
let helpWin = null;
function openHelp() {
  if (helpWin && !helpWin.isDestroyed()) { helpWin.focus(); return; }
  helpWin = new BrowserWindow({ width: 900, height: 900, title: 'Handleiding Elfsteden-log', icon: path.join(__dirname, 'icon.png') });
  helpWin.setMenuBarVisibility(false);
  helpWin.webContents.setWindowOpenHandler(({ url }) => { if (/^https?:/.test(url)) shell.openExternal(url); return { action: 'deny' }; });
  helpWin.loadFile(path.join(__dirname, 'handleiding.html'));
}
