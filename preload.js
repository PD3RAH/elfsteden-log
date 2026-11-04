const { contextBridge, ipcRenderer } = require('electron');
contextBridge.exposeInMainWorld('elfApi', {
  save: json => ipcRenderer.sendSync('save', json),
  load: () => ipcRenderer.sendSync('load'),
  openFolder: () => ipcRenderer.invoke('openFolder'),
  latestRelease: () => ipcRenderer.invoke('latestRelease')
});
