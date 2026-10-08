// Zips a staged release folder with Linux permissions set explicitly: folders 755, files 644.
// (Windows' tar copies Windows/OneDrive attributes into the zip — read-only folders became 555 on the server,
// which made cPanel's extract fail with "Permission denied" when a release adds new folders.)
// usage: node deploy/zip-release.js <stageDir> <out.zip>
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const [stage, out, stamp = new Date().toISOString().slice(0, 16).replace(/[-:]/g, '').replace('T', '-')] = process.argv.slice(2);
if (!stage || !out) { console.error('usage: node zip-release.js <stageDir> <out.zip> [releaseStamp]'); process.exit(1); }

// release-manifest.txt: every file's path exactly as stored in the zip, so `morena:deploy` can verify the upload.
const listed = [];
(function list(dir, rel) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const r = rel ? rel + '/' + e.name : e.name;
    if (e.isDirectory()) list(path.join(dir, e.name), r);
    else if (e.isFile() && r !== 'release-manifest.txt') listed.push(r);
  }
})(stage, '');
listed.sort();
fs.writeFileSync(path.join(stage, 'release-manifest.txt'), ['# ' + stamp, ...listed].join('\n') + '\n');

const fd = fs.openSync(out, 'w');
let offset = 0;
const central = [];
const write = (buf) => { fs.writeSync(fd, buf); offset += buf.length; };

// DOS date/time for "now" (zip has no time zone; unzip uses it as the file mtime).
const d = new Date();
const dosTime = (d.getHours() << 11) | (d.getMinutes() << 5) | (d.getSeconds() >> 1);
const dosDate = ((d.getFullYear() - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate();

function add(name, data, isDir) {
  const nameBuf = Buffer.from(name, 'utf8');
  const raw = data || Buffer.alloc(0);
  const packed = isDir ? raw : zlib.deflateRawSync(raw, { level: 9 });
  const method = isDir ? 0 : 8;
  const crc = isDir ? 0 : zlib.crc32(raw);
  // Laravel's runtime folders stay writable whichever user the host runs PHP as (logs, sessions, cache, compiled views).
  const runtime = isDir && /^(storage\/.+|bootstrap\/cache\/)$/.test(name);
  const mode = isDir ? (runtime ? 0o40777 : 0o40755) : 0o100644; // S_IFDIR|755 (777 runtime), S_IFREG|644
  const external = ((mode << 16) | (isDir ? 0x10 : 0)) >>> 0;

  const local = Buffer.alloc(30);
  local.writeUInt32LE(0x04034b50, 0);
  local.writeUInt16LE(20, 4);           // version needed
  local.writeUInt16LE(0x0800, 6);       // UTF-8 names
  local.writeUInt16LE(method, 8);
  local.writeUInt16LE(dosTime, 10);
  local.writeUInt16LE(dosDate, 12);
  local.writeUInt32LE(crc, 14);
  local.writeUInt32LE(packed.length, 18);
  local.writeUInt32LE(raw.length, 22);
  local.writeUInt16LE(nameBuf.length, 26);
  local.writeUInt16LE(0, 28);
  const at = offset;
  write(local); write(nameBuf); write(packed);

  const c = Buffer.alloc(46);
  c.writeUInt32LE(0x02014b50, 0);
  c.writeUInt16LE((3 << 8) | 20, 4);    // made by: Unix, spec 2.0 — so unzip honours the mode bits
  c.writeUInt16LE(20, 6);
  c.writeUInt16LE(0x0800, 8);
  c.writeUInt16LE(method, 10);
  c.writeUInt16LE(dosTime, 12);
  c.writeUInt16LE(dosDate, 14);
  c.writeUInt32LE(crc, 16);
  c.writeUInt32LE(packed.length, 20);
  c.writeUInt32LE(raw.length, 24);
  c.writeUInt16LE(nameBuf.length, 28);
  c.writeUInt32LE(external, 38);
  c.writeUInt32LE(at, 42);
  central.push(Buffer.concat([c, nameBuf]));
}

let files = 0, dirs = 0;
(function walk(dir, rel) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
    const r = rel ? rel + '/' + e.name : e.name;
    const full = path.join(dir, e.name);
    if (e.isDirectory()) { add(r + '/', null, true); dirs++; walk(full, r); }
    else if (e.isFile()) { add(r, fs.readFileSync(full), false); files++; }
  }
})(stage, '');

const cdStart = offset;
for (const c of central) write(c);
const end = Buffer.alloc(22);
end.writeUInt32LE(0x06054b50, 0);
end.writeUInt16LE(central.length, 8);
end.writeUInt16LE(central.length, 10);
end.writeUInt32LE(offset - cdStart, 12);
end.writeUInt32LE(cdStart, 16);
write(end);
fs.closeSync(fd);
if (central.length > 65535) throw new Error('too many entries for a non-zip64 archive');
console.log(`zipped ${files} files, ${dirs} folders (folders 755, storage/* + bootstrap/cache 777, files 644)`);
