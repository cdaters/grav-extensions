(function () {
  'use strict';

  if (window.PrismGallery) return;

  var focusableSelector = 'a[href],button:not([disabled]),iframe,video[controls],[tabindex]:not([tabindex="-1"])';

  function bool(value, fallback) {
    if (value === undefined || value === null || value === '') return fallback;
    return value === true || value === 'true' || value === '1';
  }

  function safeSelector(selector) {
    if (!selector) return null;
    try { return document.querySelector(selector); } catch (error) { return null; }
  }

  function withAutoplay(url, enabled) {
    if (!enabled) return url;
    try {
      var parsed = new URL(url, window.location.href);
      parsed.searchParams.set('autoplay', '1');
      return parsed.toString();
    } catch (error) {
      return url;
    }
  }

  function PrismGallery() {
    this.root = null;
    this.stage = null;
    this.caption = null;
    this.counter = null;
    this.prevButton = null;
    this.nextButton = null;
    this.zoomButton = null;
    this.closeButton = null;
    this.items = [];
    this.index = 0;
    this.opener = null;
    this.pointerStart = null;
    this.options = {};
    this.mediaCache = new WeakMap();
    this.renderRequest = 0;
    this.boundKeydown = this.onKeydown.bind(this);
  }

  PrismGallery.prototype.mount = function () {
    if (this.root) return;

    var root = document.createElement('div');
    root.className = 'prism-lightbox';
    root.hidden = true;
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-label', 'Media viewer');
    root.innerHTML = '' +
      '<button class="prism-lightbox__backdrop" type="button" aria-label="Close media viewer"></button>' +
      '<div class="prism-lightbox__shell">' +
        '<div class="prism-lightbox__toolbar">' +
          '<span class="prism-lightbox__counter" aria-live="polite"></span>' +
          '<div class="prism-lightbox__actions">' +
            '<button class="prism-lightbox__zoom" type="button" aria-label="Zoom image" title="Zoom image">+</button>' +
            '<button class="prism-lightbox__close" type="button" aria-label="Close media viewer" title="Close">×</button>' +
          '</div>' +
        '</div>' +
        '<div class="prism-lightbox__viewport">' +
          '<button class="prism-lightbox__nav prism-lightbox__prev" type="button" aria-label="Previous item" title="Previous">‹</button>' +
          '<div class="prism-lightbox__stage"></div>' +
          '<button class="prism-lightbox__nav prism-lightbox__next" type="button" aria-label="Next item" title="Next">›</button>' +
        '</div>' +
        '<div class="prism-lightbox__caption" aria-live="polite"></div>' +
      '</div>';

    document.body.appendChild(root);
    this.root = root;
    this.stage = root.querySelector('.prism-lightbox__stage');
    this.caption = root.querySelector('.prism-lightbox__caption');
    this.counter = root.querySelector('.prism-lightbox__counter');
    this.prevButton = root.querySelector('.prism-lightbox__prev');
    this.nextButton = root.querySelector('.prism-lightbox__next');
    this.zoomButton = root.querySelector('.prism-lightbox__zoom');
    this.closeButton = root.querySelector('.prism-lightbox__close');

    root.querySelector('.prism-lightbox__backdrop').addEventListener('click', function () {
      if (this.options.backdrop) this.close();
    }.bind(this));
    this.closeButton.addEventListener('click', this.close.bind(this));
    this.prevButton.addEventListener('click', this.previous.bind(this));
    this.nextButton.addEventListener('click', this.next.bind(this));
    this.zoomButton.addEventListener('click', this.toggleZoom.bind(this));
    this.stage.addEventListener('pointerdown', this.pointerDown.bind(this));
    this.stage.addEventListener('pointerup', this.pointerUp.bind(this));
    this.stage.addEventListener('dblclick', this.toggleZoom.bind(this));
  };

  PrismGallery.prototype.collect = function (trigger) {
    var group = trigger.getAttribute('data-prism-gallery');
    var all = Array.prototype.slice.call(document.querySelectorAll('.prism-trigger[data-prism-src]'));
    return group ? all.filter(function (item) { return item.getAttribute('data-prism-gallery') === group; }) : [trigger];
  };

  PrismGallery.prototype.open = function (trigger) {
    this.mount();
    this.items = this.collect(trigger);
    this.index = Math.max(0, this.items.indexOf(trigger));
    this.opener = trigger;
    this.options = {
      loop: bool(trigger.getAttribute('data-prism-loop'), true),
      keyboard: bool(trigger.getAttribute('data-prism-keyboard'), true),
      touch: bool(trigger.getAttribute('data-prism-touch'), true),
      backdrop: bool(trigger.getAttribute('data-prism-backdrop'), true),
      animation: trigger.getAttribute('data-prism-animation') || 'lift'
    };
    this.root.hidden = false;
    document.body.classList.add('prism-open');
    document.addEventListener('keydown', this.boundKeydown);
    this.render(true);
    this.closeButton.focus({ preventScroll: true });
  };

  PrismGallery.prototype.close = function () {
    if (!this.root || this.root.hidden) return;
    this.renderRequest++;
    this.stopMedia();
    this.root.hidden = true;
    this.root.classList.remove('is-opening');
    document.body.classList.remove('prism-open');
    document.removeEventListener('keydown', this.boundKeydown);
    if (this.opener && document.contains(this.opener)) this.opener.focus({ preventScroll: true });
  };

  PrismGallery.prototype.render = function (opening) {
    var item = this.items[this.index];
    if (!item) return;
    var request = ++this.renderRequest;
    var resolver = item.getAttribute('data-prism-resolve');
    var source = item.getAttribute('data-prism-src') || item.getAttribute('href');

    if (!resolver) {
      this.renderItem(item, source, opening);
      return;
    }

    this.stopMedia();
    this.stage.innerHTML = '<span class="prism-lightbox__loading" role="status">Preparing protected media…</span>';
    this.zoomButton.hidden = true;

    this.resolveProtectedMedia(item, resolver).then(function (protectedSource) {
      if (request !== this.renderRequest || !this.root || this.root.hidden) return;
      this.renderItem(item, protectedSource, opening);
    }.bind(this)).catch(function () {
      if (request !== this.renderRequest || !this.root || this.root.hidden) return;
      this.stage.innerHTML = '<p class="prism-lightbox__error" role="alert">This media could not be opened. Please close the viewer and try again.</p>';
    }.bind(this));
  };

  PrismGallery.prototype.resolveProtectedMedia = function (item, resolver) {
    var cached = this.mediaCache.get(item);
    if (cached && cached.expires * 1000 > Date.now() + 5000) {
      return Promise.resolve(cached.url);
    }

    var endpoint = new URL(resolver, window.location.href);
    if (endpoint.origin !== window.location.origin) {
      return Promise.reject(new Error('Protected media endpoint must be same-origin.'));
    }

    return fetch(endpoint.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-Prism-Request': 'media', 'Accept': 'application/json' }
    }).then(function (response) {
      if (!response.ok) throw new Error('Protected media authorization failed.');
      return response.json();
    }).then(function (result) {
      var mediaUrl = new URL(result.url, window.location.href);
      if (mediaUrl.origin !== window.location.origin || mediaUrl.pathname.indexOf('/prism-gallery/media') === -1) {
        throw new Error('Unexpected protected media response.');
      }
      var value = { url: mediaUrl.toString(), expires: Number(result.expires) || 0 };
      this.mediaCache.set(item, value);
      return value.url;
    }.bind(this));
  };

  PrismGallery.prototype.renderItem = function (item, source, opening) {
    var type = item.getAttribute('data-prism-type') || 'image';
    var title = item.getAttribute('data-prism-title') || '';
    var autoplay = bool(item.getAttribute('data-prism-autoplay'), false);
    var zoomable = type === 'image' && bool(item.getAttribute('data-prism-zoom'), true);
    var node;

    this.stopMedia();
    this.stage.innerHTML = '';

    if (type === 'image') {
      node = document.createElement('img');
      node.src = source;
      node.alt = title;
      node.decoding = 'async';
    } else if (type === 'video') {
      node = document.createElement('video');
      node.src = source;
      node.controls = true;
      node.playsInline = true;
      node.preload = 'metadata';
      if (autoplay) node.autoplay = true;
    } else {
      node = document.createElement('iframe');
      node.src = withAutoplay(source, autoplay);
      node.title = title || 'Embedded media';
      node.loading = 'eager';
      node.allow = 'autoplay; fullscreen; picture-in-picture; encrypted-media';
      node.referrerPolicy = 'strict-origin-when-cross-origin';
      node.setAttribute('allowfullscreen', '');
    }

    this.stage.appendChild(node);
    this.zoomButton.hidden = !zoomable;
    this.zoomButton.textContent = '+';
    this.zoomButton.setAttribute('aria-pressed', 'false');

    var description = safeSelector(item.getAttribute('data-prism-description'));
    this.caption.innerHTML = '';
    if (title) {
      var heading = document.createElement('strong');
      heading.textContent = title;
      this.caption.appendChild(heading);
    }
    if (description) {
      var copy = document.createElement('div');
      copy.innerHTML = description.innerHTML;
      this.caption.appendChild(copy);
    }

    this.counter.textContent = this.items.length > 1 ? (this.index + 1) + ' / ' + this.items.length : '1 / 1';
    this.prevButton.hidden = this.items.length < 2;
    this.nextButton.hidden = this.items.length < 2;
    this.prevButton.disabled = !this.canPrevious();
    this.nextButton.disabled = !this.canNext();

    this.root.classList.toggle('is-opening', !!opening && this.options.animation !== 'none');
    this.root.classList.toggle('animation-fade', this.options.animation === 'fade');
    if (opening) {
      var root = this.root;
      window.setTimeout(function () { root.classList.remove('is-opening'); }, 260);
    }
  };

  PrismGallery.prototype.canPrevious = function () {
    return this.items.length > 1 && (this.options.loop || this.index > 0);
  };

  PrismGallery.prototype.canNext = function () {
    return this.items.length > 1 && (this.options.loop || this.index < this.items.length - 1);
  };

  PrismGallery.prototype.previous = function () {
    if (!this.canPrevious()) return;
    this.index = (this.index - 1 + this.items.length) % this.items.length;
    this.render(false);
  };

  PrismGallery.prototype.next = function () {
    if (!this.canNext()) return;
    this.index = (this.index + 1) % this.items.length;
    this.render(false);
  };

  PrismGallery.prototype.toggleZoom = function () {
    var image = this.stage && this.stage.querySelector('img');
    if (!image || this.zoomButton.hidden) return;
    var zoomed = image.classList.toggle('is-zoomed');
    this.zoomButton.textContent = zoomed ? '−' : '+';
    this.zoomButton.setAttribute('aria-pressed', zoomed ? 'true' : 'false');
  };

  PrismGallery.prototype.stopMedia = function () {
    if (!this.stage) return;
    var video = this.stage.querySelector('video');
    if (video) video.pause();
    var frame = this.stage.querySelector('iframe');
    if (frame) frame.src = 'about:blank';
  };

  PrismGallery.prototype.pointerDown = function (event) {
    if (!this.options.touch) return;
    if (event.pointerType === 'mouse' && event.button !== 0) return;
    this.pointerStart = { x: event.clientX, y: event.clientY };
  };

  PrismGallery.prototype.pointerUp = function (event) {
    if (!this.options.touch) return;
    if (!this.pointerStart) return;
    var dx = event.clientX - this.pointerStart.x;
    var dy = event.clientY - this.pointerStart.y;
    this.pointerStart = null;
    if (Math.abs(dx) > 55 && Math.abs(dx) > Math.abs(dy)) {
      dx > 0 ? this.previous() : this.next();
    } else if (dy > 90 && Math.abs(dy) > Math.abs(dx)) {
      this.close();
    }
  };

  PrismGallery.prototype.onKeydown = function (event) {
    if (!this.root || this.root.hidden) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      this.close();
    } else if (this.options.keyboard && event.key === 'ArrowLeft') {
      event.preventDefault();
      this.previous();
    } else if (this.options.keyboard && event.key === 'ArrowRight') {
      event.preventDefault();
      this.next();
    } else if (this.options.keyboard && event.key === 'Home') {
      event.preventDefault();
      this.index = 0;
      this.render(false);
    } else if (this.options.keyboard && event.key === 'End') {
      event.preventDefault();
      this.index = this.items.length - 1;
      this.render(false);
    } else if (event.key === 'Tab') {
      this.trapFocus(event);
    }
  };

  PrismGallery.prototype.trapFocus = function (event) {
    var focusable = Array.prototype.slice.call(this.root.querySelectorAll(focusableSelector)).filter(function (node) {
      return !node.hidden && !node.disabled && node.offsetParent !== null;
    });
    if (!focusable.length) return;
    var first = focusable[0];
    var last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  };

  var gallery = new PrismGallery();

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest && event.target.closest('.prism-trigger[data-prism-src]');
    if (!trigger) return;
    event.preventDefault();
    gallery.open(trigger);
  });

  window.PrismGallery = gallery;
}());
