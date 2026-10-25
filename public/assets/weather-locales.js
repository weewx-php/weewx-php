// Copyright (c) 2026 Manuel Hilgert <hilman2@gmail.com>

const en = {
    temperature: 'Temperature', humidity: 'Humidity', rain: 'Rain', logger: 'Logger',
    cumulative: 'Cumulative', loggerInterval: 'Logger interval', comparison: 'Month to reference time',
    ready: '', stale: 'Stale', pending: 'Pending', unavailable: 'No data', loading: 'Loading…',
    weather: 'Weather', value: 'Value', live: 'Live', archive: 'Archive', astronomy: 'Astronomy',
    disconnected: 'Connection lost', invalidAPI: 'Invalid API address',
};
const de = {
    temperature: 'Temperatur', humidity: 'Luftfeuchte', rain: 'Niederschlag', logger: 'Logger',
    cumulative: 'Kumuliert', loggerInterval: 'Loggerintervall', comparison: 'Monat bis Referenzzeit',
    ready: '', stale: 'Veraltet', pending: 'Ausstehend', unavailable: 'Keine Daten', loading: 'Laden…',
    weather: 'Wetter', value: 'Wert', live: 'Live', archive: 'Archiv', astronomy: 'Astronomie',
    disconnected: 'Verbindung unterbrochen', invalidAPI: 'Ungültige API-Adresse',
};

export const weatherText = (locale = 'en', labels = {}) => ({...en,
    ...(locale.toLowerCase().split('-')[0] === 'de' ? de : {}), ...labels});
