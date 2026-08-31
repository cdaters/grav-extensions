const CONTROL_CHARACTERS = /[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]/;
const CONTROL_CHARACTERS_GLOBAL = /[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]/g;
const PROTOTYPE_KEYS = new Set(['__proto__', 'constructor', 'prototype']);

export function safeLinkUrl(value) {
  const url = String(value ?? '').trim();
  if (url === '' || CONTROL_CHARACTERS.test(url)) return null;
  if (/^(?:https?:|mailto:|tel:)/i.test(url)) return url;
  if (/^(?:[./?#]|[^:/?#\s]+(?:[/?#]|$))/.test(url) && !/^[a-z][a-z0-9+.-]*:/i.test(url)) {
    return url;
  }
  return null;
}

export function safeMediaReference(value) {
  const url = String(value ?? '').trim();
  if (url === '' || CONTROL_CHARACTERS.test(url) || /^\/\//.test(url)) return null;
  if (/^https:\/\//i.test(url)) return url;
  if (/^(?:[./?#]|[^:/?#\s]+(?:[/?#]|$))/.test(url) && !/^[a-z][a-z0-9+.-]*:/i.test(url)) {
    return url;
  }
  return null;
}

export function opaquePreview(source, maximum = 240) {
  const safe = String(source ?? '').replace(CONTROL_CHARACTERS_GLOBAL, '\ufffd');
  return safe.length <= maximum ? safe : `${safe.slice(0, maximum)}\u2026`;
}

export function boundedOwnRecord(input, allowedKeys) {
  if (input === null || typeof input !== 'object' || Array.isArray(input)) {
    throw new TypeError('Expected a plain record.');
  }
  const prototype = Object.getPrototypeOf(input);
  if (prototype !== Object.prototype && prototype !== null) {
    throw new TypeError('Expected a plain record.');
  }
  const output = Object.create(null);
  for (const key of Object.keys(input)) {
    if (PROTOTYPE_KEYS.has(key) || !allowedKeys.includes(key)) {
      throw new TypeError(`Unsupported record key: ${key}`);
    }
    output[key] = input[key];
  }
  return output;
}

export function hasUnsafeUrl(source) {
  const candidates = [];
  for (const match of source.matchAll(/!?\[[^\]]*\]\(\s*<?([^\s)>]+)>?/g)) candidates.push(match[1]);
  for (const match of source.matchAll(/<([a-z][a-z0-9+.-]*:[^>]+)>/gi)) candidates.push(match[1]);
  return candidates.some((candidate) => (
    /&(?:#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]+);/i.test(candidate)
    || safeLinkUrl(candidate) === null
  ));
}
