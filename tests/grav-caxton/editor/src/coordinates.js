export class SourceCoordinateMap {
  static async create(source) {
    if (typeof source !== 'string') throw new TypeError('Coordinate source must be a string.');
    if (!globalThis.crypto?.subtle) throw new Error('Web Crypto SHA-256 is required.');
    const bytes = new TextEncoder().encode(source);
    const digest = await globalThis.crypto.subtle.digest('SHA-256', bytes);
    const identity = Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
    return new SourceCoordinateMap(source, identity, bytes.length);
  }

  constructor(source, identity, byteLength) {
    this.source = source;
    this.identity = identity;
    this.byteLength = byteLength;
  }

  utf16ToByte(offset) {
    if (!Number.isInteger(offset) || offset < 0 || offset > this.source.length) return null;
    if (offset > 0 && offset < this.source.length
      && /[\uD800-\uDBFF]/.test(this.source[offset - 1])
      && /[\uDC00-\uDFFF]/.test(this.source[offset])) return null;
    return new TextEncoder().encode(this.source.slice(0, offset)).length;
  }

  byteToUtf16(offset) {
    if (!Number.isInteger(offset) || offset < 0 || offset > this.byteLength) return null;
    let bytes = 0;
    let units = 0;
    for (const character of this.source) {
      if (bytes === offset) return units;
      bytes += new TextEncoder().encode(character).length;
      units += character.length;
      if (bytes > offset) return null;
    }
    return bytes === offset ? units : null;
  }

  selectionToBytes(from, to = from) {
    const byteFrom = this.utf16ToByte(from);
    const byteTo = this.utf16ToByte(to);
    return byteFrom === null || byteTo === null ? null : {
      from: byteFrom,
      to: byteTo,
      sourceIdentity: this.identity,
    };
  }
}
