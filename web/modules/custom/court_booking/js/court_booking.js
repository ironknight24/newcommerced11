/**
 * @file
 * Court booking: sport, month strip + calendar modal, duration, multi-slot times, pitches.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * @param {string} iso
   * @param {string} timeZone
   * @returns {string}
   */
  function ymdInZone(iso, timeZone) {
    const parts = new Intl.DateTimeFormat('en-CA', {
      timeZone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).formatToParts(new Date(iso));
    const y = parts.find((p) => p.type === 'year')?.value;
    const m = parts.find((p) => p.type === 'month')?.value;
    const d = parts.find((p) => p.type === 'day')?.value;
    return `${y}-${m}-${d}`;
  }

  /**
   * @param {string} timeZone
   * @returns {string}
   */
  function todayYmd(timeZone) {
    const parts = new Intl.DateTimeFormat('en-CA', {
      timeZone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).formatToParts(new Date());
    const y = parts.find((p) => p.type === 'year')?.value;
    const m = parts.find((p) => p.type === 'month')?.value;
    const d = parts.find((p) => p.type === 'day')?.value;
    return `${y}-${m}-${d}`;
  }

  /**
   * @param {string} hm
   * @returns {number|null}
   */
  function parseHmMinutes(hm) {
    const raw = String(hm || '').trim();
    if (!/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(raw)) {
      return null;
    }
    const [h, min] = raw.split(':').map((x) => parseInt(x, 10));
    return h * 60 + min;
  }

  /**
   * @param {string} ymd
   * @param {string} timeZone
   * @returns {number}
   */
  function minutesNowInZoneForYmd(ymd, timeZone) {
    const today = todayYmd(timeZone);
    if (ymd !== today) {
      return -1;
    }
    const parts = new Intl.DateTimeFormat('en-GB', {
      timeZone,
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    }).formatToParts(new Date());
    const h = parseInt(parts.find((p) => p.type === 'hour')?.value || '0', 10);
    const m = parseInt(parts.find((p) => p.type === 'minute')?.value || '0', 10);
    return h * 60 + m;
  }

  /**
   * @param {string} iso
   * @param {string} timeZone
   * @returns {number}
   */
  function minutesSinceMidnightInZone(iso, timeZone) {
    const parts = new Intl.DateTimeFormat('en-GB', {
      timeZone,
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    }).formatToParts(new Date(iso));
    const h = parseInt(parts.find((p) => p.type === 'hour')?.value || '0', 10);
    const min = parseInt(parts.find((p) => p.type === 'minute')?.value || '0', 10);
    return h * 60 + min;
  }

  /**
   * @param {object} entry
   * @returns {boolean}
   */
  function isEntrySelectable(entry) {
    if (!entry) {
      return false;
    }
    if (entry.status === 'closed' || entry.booking_window_exceeded) {
      return false;
    }
    return entry.status === 'available' && Number(entry.remaining) > 0;
  }

  /**
   * @param {string|undefined} raw
   * @returns {string}
   */
  function normalizeDrupalTimeZoneId(raw) {
    const str = typeof raw === 'string' ? raw.trim() : '';
    if (!str) {
      return '';
    }
    try {
      Intl.DateTimeFormat(undefined, { timeZone: str }).format(0);
      return str;
    } catch (e) {
      return '';
    }
  }

  /** @type {string|null} */
  let memoResolvedIntlLocale = null;

  /**
   * Picks a locale string the JS engine accepts (falls back if -u-nu-* unsupported).
   *
   * @param {string} raw
   * @returns {string}
   */
  function normalizeIntlLocaleForEngine(raw) {
    let candidate = raw;
    for (let attempt = 0; attempt < 2; attempt++) {
      try {
        new Intl.DateTimeFormat(candidate).format(0);
        return candidate;
      } catch (e) {
        if (attempt === 0 && candidate.includes('-u-nu-')) {
          candidate = candidate.split('-u-')[0];
          continue;
        }
      }
      break;
    }
    return 'en';
  }

  /**
   * Locale for user-visible Intl formatting (matches Drupal interface language).
   * `intlLocale` may include -u-nu-* for native numerals (e.g. Arabic).
   *
   * @returns {string}
   */
  function interfaceIntlLocale() {
    if (memoResolvedIntlLocale !== null) {
      return memoResolvedIntlLocale;
    }
    const s = drupalSettings.courtBooking;
    let raw = 'en';
    if (s && typeof s === 'object') {
      const loc = s.intlLocale || s.interfaceLangcode;
      if (typeof loc === 'string' && loc.trim() !== '') {
        raw = loc.trim();
      }
    }
    memoResolvedIntlLocale = normalizeIntlLocaleForEngine(raw);
    return memoResolvedIntlLocale;
  }

  /**
   * @param {Record<string, object>} cal
   * @returns {object[]}
   */
  function calendarEntries(cal) {
    if (!cal || typeof cal !== 'object') {
      return [];
    }
    return Object.values(cal);
  }

  /**
   * @param {string} str
   * @returns {string}
   */
  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /**
   * @param {string} ym
   * @param {number} delta
   * @returns {string}
   */
  function addMonthsYm(ym, delta) {
    const [y, m] = ym.split('-').map((x) => parseInt(x, 10));
    const d = new Date(Date.UTC(y, m - 1 + delta, 1));
    const yy = d.getUTCFullYear();
    const mm = String(d.getUTCMonth() + 1).padStart(2, '0');
    return `${yy}-${mm}`;
  }

  /**
   * @param {string} ymd
   * @param {string} tz
   * @returns {string}
   */
  function formatMonthYearHeading(ymd, tz) {
    const [y, m, d] = ymd.split('-').map((x) => parseInt(x, 10));
    const utcNoon = Date.UTC(y, m - 1, d, 12, 0, 0);
    return new Intl.DateTimeFormat(interfaceIntlLocale(), {
      timeZone: tz,
      month: 'short',
      year: 'numeric',
    }).format(new Date(utcNoon));
  }

  /**
   * Localized weekday + day number for #cb-dates (uses intlLocale / native numerals).
   *
   * @param {string} ymd
   * @param {string} tz
   * @returns {{ weekdayShort: string, dayNumeric: string }}
   */
  function formatDateStripLabels(ymd, tz) {
    const [y, m, d] = ymd.split('-').map((x) => parseInt(x, 10));
    const utcNoon = Date.UTC(y, m - 1, d, 12, 0, 0);
    const inst = new Date(utcNoon);
    const loc = interfaceIntlLocale();
    const weekdayShort = new Intl.DateTimeFormat(loc, {
      timeZone: tz,
      weekday: 'short',
    }).format(inst);
    const dayNumeric = new Intl.DateTimeFormat(loc, {
      timeZone: tz,
      day: 'numeric',
    }).format(inst);
    return { weekdayShort, dayNumeric };
  }

  /**
   * @param {number} year
   * @param {number} monthIndex0
   * @returns {string}
   */
  function modalMonthLongLabel(year, monthIndex0) {
    const utcNoon = Date.UTC(year, monthIndex0, 15, 12, 0, 0);
    return new Intl.DateTimeFormat(interfaceIntlLocale(), { month: 'long', year: 'numeric' }).format(
      new Date(utcNoon),
    );
  }

  function gcdPlayGrid(a, b) {
    let x = Math.abs(a);
    let y = Math.abs(b);
    while (y) {
      const t = y;
      y = x % y;
      x = t;
    }
    return Math.max(1, x);
  }

  function lcmPlayGrid(a, b) {
    if (a <= 0 || b <= 0) {
      return Math.max(a, b, 1);
    }
    return (a / gcdPlayGrid(a, b)) * b;
  }

  /**
   * @param {number[]} arr
   */
  function lcmManyPlayGrid(arr) {
    const vals = [...new Set(arr.filter((n) => n > 0))];
    if (!vals.length) {
      return 60;
    }
    let l = vals[0];
    for (let i = 1; i < vals.length; i++) {
      l = lcmPlayGrid(l, vals[i]);
    }
    return Math.max(1, l);
  }

  /**
   * @param {number} minutes
   * @returns {string}
   */
  function formatPlayDurationLabel(minutes) {
    const m = Math.max(1, Math.round(Number(minutes) || 0));
    if (m < 60) {
      return Drupal.formatPlural(m, '1 minute', '@count minutes');
    }
    if (m % 60 === 0) {
      const h = m / 60;
      return Drupal.formatPlural(h, '1 hour', '@count hours');
    }
    const h = Math.floor(m / 60);
    const rem = m % 60;
    return Drupal.t('@h h @m min', { '@h': String(h), '@m': String(rem) });
  }

  /**
   * @param {object} v
   * @param {number} slotMinutesDefault
   * @param {number} playMinutes
   * @returns {number|null}
   */
  function slotCountForVariation(v, slotMinutesDefault, playMinutes) {
    const slotLen = Math.max(1, Number(v.slotMinutes) || slotMinutesDefault);
    const totalMin = Math.max(0, Number(playMinutes) || 0);
    if (totalMin % slotLen !== 0) {
      return null;
    }
    return totalMin / slotLen;
  }

  /**
   * BAT consecutive slots to reserve (ceil) when buffer > 0; e.g. 70 min on hourly → 2 slots.
   *
   * @param {object} v
   * @param {number} slotMinutesDefault
   * @param {number} playMinutes
   * @param {number} bufferMinutes
   * @returns {number|null}
   */
  function blockSlotCountForVariation(v, slotMinutesDefault, playMinutes, bufferMinutes) {
    if (!bufferMinutes || bufferMinutes <= 0) {
      return null;
    }
    const slotLen = Math.max(1, Number(v.slotMinutes) || slotMinutesDefault);
    const playMin = Math.max(0, Number(playMinutes) || 0);
    return Math.ceil((playMin + bufferMinutes) / slotLen);
  }

  /**
   * Buffer &gt; 0: only starts on (play + buffer) cadence from opening.
   * Buffer 0: only starts every playMinutes from anchor (opening if configured window, else midnight).
   *
   * @param {string} startIso
   * @param {string} timeZone
   * @param {number|null} openM
   * @param {boolean} hasWindow
   * @param {number} bufferMinutes
   * @param {number} playMinutes
   * @returns {boolean}
   */
  function matchesStaggeredStart(startIso, timeZone, openM, hasWindow, bufferMinutes, playMinutes) {
    const startM = minutesSinceMidnightInZone(startIso, timeZone);
    const playMin = Math.max(1, Number(playMinutes) || 60);
    if (bufferMinutes > 0) {
      if (openM === null) {
        return true;
      }
      const step = playMin + bufferMinutes;
      if (startM < openM) {
        return false;
      }
      return (startM - openM) % step === 0;
    }
    const anchor = hasWindow && openM !== null ? openM : 0;
    if (hasWindow && openM !== null && startM < openM) {
      return false;
    }
    return (startM - anchor) % playMin === 0;
  }

  /**
   * Rental field / POST end: start + play + buffer (exact), not necessarily last BAT grid boundary.
   *
   * @param {string} startIso
   * @param {number} playMinutes
   * @param {number} bufferMinutes
   * @returns {string}
   */
  function playBufferEndIso(startIso, playMinutes, bufferMinutes) {
    const ms = new Date(startIso).getTime();
    if (Number.isNaN(ms)) {
      return '';
    }
    const addMin = Math.max(0, Number(playMinutes) || 0) + Math.max(0, bufferMinutes);
    return new Date(ms + addMin * 60000).toISOString();
  }

  /**
   * @param {string} startIso
   * @param {string} blockEndIso
   * @param {string} timeZone
   * @param {boolean} hasWindow
   * @param {number} openM
   * @param {number} closeM
   * @returns {boolean}
   */
  function slotFitsBookingWindow(startIso, blockEndIso, timeZone, hasWindow, openM, closeM) {
    if (!hasWindow) {
      return true;
    }
    const startM = minutesSinceMidnightInZone(startIso, timeZone);
    const endBlockM = minutesSinceMidnightInZone(blockEndIso, timeZone);
    if (endBlockM < startM) {
      return false;
    }
    return startM >= openM && endBlockM <= closeM;
  }

  /**
   * @param {string} startIso
   * @param {string} blockEndIso
   * @param {string} timeZone
   * @returns {string}
   */
  function formatSlotRangeLabel(startIso, blockEndIso, timeZone) {
    const opts = { hour: 'numeric', minute: '2-digit', timeZone };
    const loc = interfaceIntlLocale();
    const a = new Date(startIso).toLocaleTimeString(loc, opts);
    const b = new Date(blockEndIso).toLocaleTimeString(loc, opts);
    return `${a} – ${b}`;
  }

  /**
   * @param {HTMLButtonElement} btn
   * @param {string} startIso
   * @param {string} [endIso]
   * @param {string} timeZone
   */
  function setTimeSlotPillContent(btn, startIso, endIso, timeZone) {
    btn.textContent = '';
    const d = new Date(startIso);
    const parts = new Intl.DateTimeFormat(interfaceIntlLocale(), {
      hour: 'numeric',
      minute: '2-digit',
      hour12: true,
      timeZone,
    }).formatToParts(d);
    let hour = '';
    let minute = '';
    let dayPeriod = '';
    parts.forEach((p) => {
      if (p.type === 'hour') {
        hour = p.value;
      }
      if (p.type === 'minute') {
        minute = p.value;
      }
      if (p.type === 'dayPeriod') {
        dayPeriod = p.value;
      }
    });
    const timeLine = minute !== '' ? `${hour}:${minute}` : hour;
    const top = document.createElement('span');
    top.className = 'block text-[0.95rem] font-semibold leading-tight';
    top.textContent = timeLine;
    btn.appendChild(top);
    if (dayPeriod) {
      const bot = document.createElement('span');
      bot.className = 'block text-[0.65rem] font-bold leading-none';
      bot.textContent = String(dayPeriod).toUpperCase();
      btn.appendChild(bot);
    }
    if (endIso) {
      btn.setAttribute('aria-label', formatSlotRangeLabel(startIso, endIso, timeZone));
    } else {
      btn.setAttribute('aria-label', `${timeLine}${dayPeriod ? ` ${dayPeriod}` : ''}`);
    }
  }

  /**
   * @param {boolean} on
   * @param {boolean} disabled
   * @returns {string}
   */
  function timeSlotPillClasses(on, disabled) {
    const base =
      'inline-flex min-w-[4.25rem] flex-col items-center justify-center gap-0.5 rounded-xl border px-3 py-2 text-center transition focus:outline-none focus-visible:ring-2 focus-visible:ring-[#02216E] focus-visible:ring-offset-2';
    if (disabled) {
      return `${base} cursor-not-allowed border-slate-200 bg-slate-100 text-slate-400`;
    }
    if (on) {
      return `${base} border-[#02216E] bg-[#02216E] text-white`;
    }
    return `${base} border-[#02216E] bg-white text-[#02216E] hover:bg-sky-50`;
  }

  /**
   * @param {object} cal
   * @param {string} startIso
   * @param {number} slotCount
   * @returns {{start: string, end: string}|null}
   */
  function consecutiveBlock(cal, startIso, slotCount) {
    let cur = startIso;
    let lastEnd = null;
    for (let i = 0; i < slotCount; i++) {
      const entry = calendarEntries(cal).find((e) => e.start === cur);
      if (!entry || !isEntrySelectable(entry)) {
        return null;
      }
      lastEnd = entry.end;
      cur = entry.end;
    }
    return lastEnd ? { start: startIso, end: lastEnd } : null;
  }

  /**
   * Start ISO of each consecutive calendar segment (for multi-tile selection UI).
   *
   * @param {object} cal
   * @param {string} startIso
   * @param {number} slotCount
   * @returns {string[]|null}
   */
  function consecutiveBlockStarts(cal, startIso, slotCount) {
    const starts = [];
    let cur = startIso;
    for (let i = 0; i < slotCount; i++) {
      const entry = calendarEntries(cal).find((e) => e.start === cur);
      if (!entry || !isEntrySelectable(entry)) {
        return null;
      }
      starts.push(entry.start);
      cur = entry.end;
    }
    return starts.length ? starts : null;
  }

  /**
   * @param {object} v
   * @param {number} hours
   * @returns {string}
   */
  function formatPriceForDuration(v, playMinutes, slotMinutesDefault) {
    if (v.priceAmount !== '' && v.priceAmount !== undefined && v.priceCurrencyCode) {
      const num = Number(v.priceAmount);
      if (!Number.isNaN(num)) {
        const slotLen = Math.max(1, Number(v.slotMinutes) || slotMinutesDefault);
        const pm = Math.max(0, Number(playMinutes) || 0);
        const units = pm / slotLen;
        if (!Number.isFinite(units) || units <= 0) {
          return v.price || '';
        }
        return new Intl.NumberFormat(interfaceIntlLocale(), {
          style: 'currency',
          currency: v.priceCurrencyCode,
        }).format(num * units);
      }
    }
    return v.price || '';
  }

  /**
   * @param {string} bookedEndIso
   * @param {number} bufferMinutes
   * @param {string} timeZone
   * @returns {string}
   */
  /**
   * Short cart/court line when buffer > 0 (time range already includes buffer end).
   *
   * @param {number} bufferMinutes
   * @returns {string}
   */
  function formatOptionalBufferCaption(bufferMinutes) {
    const mins = Math.max(0, Number(bufferMinutes) || 0);
    if (!mins) {
      return '';
    }
    return Drupal.t('Price is for play time only; the listed time includes @n min buffer.', {
      '@n': String(mins),
    });
  }

  Drupal.behaviors.courtBooking = {
    attach(context) {
      once('court-booking', '.court-booking-page', context).forEach((root) => {
        const s = drupalSettings.courtBooking;
        if (!s || !Array.isArray(s.sports)) {
          return;
        }

        const elSports = root.querySelector('#cb-sports');
        const elDates = root.querySelector('#cb-dates');
        const elTimes = root.querySelector('#cb-times');
        const elCourts = root.querySelector('#cb-courts');
        const elStatus = root.querySelector('#cb-status');
        const elMonthYear = root.querySelector('#cb-month-year');
        const elStripPrev = root.querySelector('#cb-strip-prev');
        const elStripNext = root.querySelector('#cb-strip-next');
        const elDatesPrev = root.querySelector('#cb-dates-prev');
        const elDatesNext = root.querySelector('#cb-dates-next');
        const elCalOpen = root.querySelector('#cb-cal-open');
        const elDuration = root.querySelector('#cb-duration');
        const elModal = root.querySelector('#cb-modal');
        const elModalBackdrop = root.querySelector('#cb-modal-backdrop');
        const elModalDialog = root.querySelector('#cb-modal-dialog');
        const elCalClose = root.querySelector('#cb-cal-close');
        const elCalPrev = root.querySelector('#cb-cal-prev');
        const elCalNext = root.querySelector('#cb-cal-next');
        const elCalMonthLabel = root.querySelector('#cb-cal-month-label');
        const elCalGrid = root.querySelector('#cb-cal-grid');
        const elCalApply = root.querySelector('#cb-cal-apply');

        let dates = Array.isArray(s.dates) ? s.dates.map((d) => ({ ...d })) : [];
        let blackoutYmd = new Set(Array.isArray(s.blackoutDates) ? s.blackoutDates.map((d) => String(d)) : []);
        let resourceClosuresByVariation =
          s.resourceClosuresByVariation && typeof s.resourceClosuresByVariation === 'object'
            ? s.resourceClosuresByVariation
            : {};
        const siteTimeZoneId = normalizeDrupalTimeZoneId(s.timezone) || 'UTC';
        const slotMinutesDefault = Math.max(1, parseInt(String(s.slotMinutes || 60), 10));
        let bufferMinutes = Math.max(0, parseInt(String(s.bufferMinutes || 0), 10));
        let sameDayCutoffMins = parseHmMinutes(s.sameDayCutoffHm);
        let sameDayCutoffHmDisplay = String(s.sameDayCutoffHm || '');
        let maxBookingHours = Math.max(1, Math.min(24, parseInt(String(s.maxBookingHours || 4), 10)));
        let bookingDayStart = String(s.bookingDayStart || '06:00');
        let bookingDayEnd = String(s.bookingDayEnd || '23:00');
        const firstDayOfWeek = Math.max(0, Math.min(6, parseInt(String(s.firstDayOfWeek ?? 0), 10)));

        /** @type {Set<string>} */
        let bookableYmd = new Set();

        function rebuildBookableYmd() {
          bookableYmd = new Set(dates.map((d) => d.ymd).filter((ymd) => !blackoutYmd.has(ymd)));
        }
        rebuildBookableYmd();

        /** @type {string|null} */
        let sportId = null;
        /** @type {string|null} */
        let selectedYmd = null;
        /** @type {{ymd: string, from: string, to: string}|null} */
        let selectedDay = null;
        /** @type {string|null} */
        let selectedStartIso = null;
        /** @type {Record<string, object>|null} */
        let calendars = null;
        /** @type {Array<{start: string, end: string, variationIds: number[]}>|null} */
        let bufferSlotCandidates = null;
        /** @type {string} yyyy-mm */
        let visibleYm = '';
        /** @type {number} Billable play length in minutes (aligned to sport duration grid). */
        let playMinutes = 60;
        /** @type {{year: number, month0: number}} */
        let modalView = { year: new Date().getFullYear(), month0: new Date().getMonth() };
        /** @type {string|null} */
        let modalPendingYmd = null;
        /** @type {Element|null} */
        let focusBeforeModal = null;

        function setStatus(msg) {
          if (!elStatus) {
            return;
          }
          elStatus.textContent = msg || '';
        }

        /**
         * Show or hide duration, time slot, and pitch sections (parent <section> of each block).
         *
         * @param {boolean} visible
         */
        function setBookingFlowSectionsVisible(visible) {
          [elDuration, elTimes, elCourts].forEach((el) => {
            if (!el) {
              return;
            }
            const section = el.closest('section');
            if (section) {
              section.hidden = !visible;
            }
          });
        }

        function pillClasses(on, disabled) {
          const base =
            'rounded-full border px-4 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-[#02216E] focus-visible:ring-offset-2';
          if (disabled) {
            return `${base} cursor-not-allowed border-slate-200 bg-slate-100 text-slate-400`;
          }
          if (on) {
            return `${base} border-[#02216E] bg-[#02216E] text-white`;
          }
          return `${base} border-slate-300 bg-white text-slate-800 hover:border-[#02216E]`;
        }

        function updateMonthYearLabel() {
          if (!elMonthYear) {
            return;
          }
          if (selectedYmd) {
            elMonthYear.textContent = formatMonthYearHeading(selectedYmd, siteTimeZoneId).toUpperCase();
          } else if (visibleYm) {
            const [y, m] = visibleYm.split('-').map((x) => parseInt(x, 10));
            const ymd = `${y}-${String(m).padStart(2, '0')}-01`;
            elMonthYear.textContent = formatMonthYearHeading(ymd, siteTimeZoneId).toUpperCase();
          } else {
            elMonthYear.textContent = '';
          }
        }

        function daysInVisibleMonth() {
          return dates.filter((d) => d.ymd.slice(0, 7) === visibleYm);
        }

        function firstBookableDayInMonth(ym) {
          return dates.find((d) => d.ymd.slice(0, 7) === ym && bookableYmd.has(d.ymd)) || null;
        }

        function selectDay(day) {
          if (!day) {
            return;
          }
          if (!bookableYmd.has(day.ymd)) {
            selectedYmd = day.ymd;
            selectedDay = null;
            selectedStartIso = null;
            calendars = null;
            bufferSlotCandidates = null;
            visibleYm = day.ymd.slice(0, 7);
            renderDateStrip();
            updateMonthYearLabel();
            elTimes.innerHTML = '';
            elCourts.innerHTML = '';
            setBookingFlowSectionsVisible(true);
            setStatus(Drupal.t('Bookings are closed on this date.'));
            return;
          }
          selectedYmd = day.ymd;
          selectedDay = { ymd: day.ymd, from: day.from, to: day.to };
          selectedStartIso = null;
          visibleYm = day.ymd.slice(0, 7);
          renderDateStrip();
          updateMonthYearLabel();
          elTimes.innerHTML = '';
          elCourts.innerHTML = '';
          loadDayData();
        }

        function sportForDurationSelect() {
          if (sportId == null || sportId === undefined) {
            return undefined;
          }
          return s.sports.find((sp) => String(sp.id) === String(sportId));
        }

        function durationGridMinutesResolved(sport) {
          if (!sport || !Array.isArray(sport.variations) || !sport.variations.length) {
            return Math.max(1, slotMinutesDefault);
          }
          if (sport.durationGridMinutes) {
            return Math.max(1, parseInt(String(sport.durationGridMinutes), 10) || slotMinutesDefault);
          }
          return lcmManyPlayGrid(
            sport.variations.map((v) => Math.max(1, Number(v.slotMinutes) || slotMinutesDefault)),
          );
        }

        function maxPlayMinutesResolved() {
          return Math.max(1, Math.min(24, maxBookingHours)) * 60;
        }

        function populateDurationSelect() {
          if (!elDuration) {
            return;
          }
          const sport = sportForDurationSelect();
          const grid = durationGridMinutesResolved(sport);
          const cap = maxPlayMinutesResolved();
          elDuration.innerHTML = '';
          for (let m = grid; m <= cap; m += grid) {
            const opt = document.createElement('option');
            opt.value = String(m);
            opt.textContent = formatPlayDurationLabel(m);
            elDuration.appendChild(opt);
          }
          if (!elDuration.options.length) {
            const fallback = Math.min(60, cap);
            const opt = document.createElement('option');
            opt.value = String(fallback);
            opt.textContent = formatPlayDurationLabel(fallback);
            elDuration.appendChild(opt);
          }
          let want = playMinutes;
          if (want < grid || want > cap || want % grid !== 0) {
            want = grid;
          }
          playMinutes = want;
          elDuration.value = String(playMinutes);
        }

        function renderSports() {
          elSports.innerHTML = '';
          s.sports.forEach((sport) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.setAttribute('role', 'tab');
            btn.setAttribute('aria-pressed', 'false');
            btn.className = pillClasses(false, false);
            btn.textContent = sport.label;
            btn.dataset.sportId = String(sport.id);
            btn.addEventListener('click', () => {
              sportId = String(sport.id);
              selectedStartIso = null;
              calendars = null;
              bufferSlotCandidates = null;
              elSports.querySelectorAll('button').forEach((b) => {
                const on = b.dataset.sportId === sportId;
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
                b.className = pillClasses(on, false);
              });
              applySportBookingRules();
              elTimes.innerHTML = '';
              elCourts.innerHTML = '';
              setStatus('');
              if (selectedYmd && bookableYmd.has(selectedYmd)) {
                selectedDay = dates.find((d) => d.ymd === selectedYmd) || null;
                visibleYm = selectedYmd.slice(0, 7);
                renderDateStrip();
                updateMonthYearLabel();
                if (selectedDay) {
                  loadDayData();
                }
              } else if (dates.length) {
                visibleYm = dates[0].ymd.slice(0, 7);
                renderDateStrip();
                updateMonthYearLabel();
                selectDay(firstBookableDayInMonth(visibleYm));
              }
            });
            elSports.appendChild(btn);
          });
          if (s.sports.length) {
            const want = s.initialSportId ? String(s.initialSportId) : '';
            const match = want && s.sports.some((sp) => String(sp.id) === want);
            sportId = String(match ? want : s.sports[0].id);
            elSports.querySelectorAll('button').forEach((b) => {
              const on = b.dataset.sportId === String(sportId);
              b.setAttribute('aria-pressed', on ? 'true' : 'false');
              b.className = pillClasses(on, false);
            });
            applySportBookingRules();
          }
          else {
            populateDurationSelect();
          }
        }

        function renderDateStrip() {
          elDates.innerHTML = '';
          if (!dates.length) {
            const p = document.createElement('p');
            p.className = 'text-sm text-slate-500';
            p.textContent = Drupal.t('No bookable dates in the current range.');
            elDates.appendChild(p);
            return;
          }
          if (!visibleYm) {
            visibleYm = dates[0].ymd.slice(0, 7);
          }
          const slice = daysInVisibleMonth();
          if (!slice.length) {
            const p = document.createElement('p');
            p.className = 'text-sm text-slate-500';
            p.textContent = Drupal.t('No dates in this month. Use the arrows or calendar.');
            elDates.appendChild(p);
            return;
          }
          slice.forEach((day) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.setAttribute('role', 'listitem');
            btn.dataset.ymd = day.ymd;
            const disabled = !bookableYmd.has(day.ymd);
            const on = day.ymd === selectedYmd;
            btn.className = disabled
              ? 'min-w-[4.5rem] shrink-0 cursor-not-allowed rounded-xl border border-slate-200 bg-slate-100 px-3 py-3 text-center text-slate-400 shadow-sm'
              : on
                ? 'min-w-[4.5rem] shrink-0 rounded-xl border-2 border-[#02216E] bg-[#02216E] px-3 py-3 text-center text-white shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-[#02216E]'
                : 'min-w-[4.5rem] shrink-0 rounded-xl border border-slate-200 bg-white px-3 py-3 text-center text-slate-800 shadow-sm hover:border-[#02216E] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#02216E]';
            const stripLabels = formatDateStripLabels(day.ymd, siteTimeZoneId);
            btn.innerHTML = `<span class="block text-xs font-medium uppercase ${on ? 'text-white' : 'text-slate-500'}">${escapeHtml(
              stripLabels.weekdayShort,
            )}</span><span class="mt-1 block font-display text-lg font-semibold">${escapeHtml(
              stripLabels.dayNumeric,
            )}</span>`;
            if (disabled) {
              btn.disabled = true;
              btn.setAttribute('aria-disabled', 'true');
              btn.title = Drupal.t('Bookings are closed on this date.');
            }
            btn.addEventListener('click', () => {
              selectDay(day);
            });
            elDates.appendChild(btn);
          });
          requestAnimationFrame(() => {
            updateDateNavButtons();
          });
          scrollToSelectedDate();
        }

        function updateDateNavButtons() {
          if (!elDatesPrev || !elDatesNext) {
            return;
          }
          const el = elDates;
          const canScrollLeft = el.scrollLeft > 1;
          const canScrollRight = el.scrollLeft + el.clientWidth < el.scrollWidth - 1;
          elDatesPrev.classList.toggle('hidden', !canScrollLeft);
          elDatesPrev.classList.toggle('sm:flex', canScrollLeft);
          elDatesNext.classList.toggle('hidden', !canScrollRight);
          elDatesNext.classList.toggle('sm:flex', canScrollRight);
        }

        function scrollToSelectedDate() {
          if (!selectedYmd) {
            return;
          }
          requestAnimationFrame(() => {
            const btn = elDates.querySelector('[data-ymd="' + selectedYmd + '"]');
            if (btn) {
              btn.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            }
            updateDateNavButtons();
          });
        }

        function currentSport() {
          if (sportId === null || sportId === undefined) {
            return undefined;
          }
          const sid = String(sportId);
          return s.sports.find((sp) => String(sp.id) === sid);
        }

        function applySportBookingRules() {
          const sp = currentSport();
          const b = sp && sp.booking ? sp.booking : null;
          if (!b) {
            rebuildBookableYmd();
            populateDurationSelect();
            return;
          }
          dates = Array.isArray(b.dates) ? b.dates.map((x) => ({ ...x })) : [];
          blackoutYmd = new Set(Array.isArray(b.blackoutDates) ? b.blackoutDates.map((d) => String(d)) : []);
          resourceClosuresByVariation =
            b.resourceClosuresByVariation && typeof b.resourceClosuresByVariation === 'object'
              ? b.resourceClosuresByVariation
              : {};
          bufferMinutes = Math.max(0, parseInt(String(b.bufferMinutes ?? 0), 10));
          sameDayCutoffMins = parseHmMinutes(b.sameDayCutoffHm || '');
          sameDayCutoffHmDisplay = String(b.sameDayCutoffHm || '');
          maxBookingHours = Math.max(1, Math.min(24, parseInt(String(b.maxBookingHours || 4), 10)));
          bookingDayStart = String(b.bookingDayStart || '06:00');
          bookingDayEnd = String(b.bookingDayEnd || '23:00');
          rebuildBookableYmd();
          populateDurationSelect();
        }

        /**
         * Calendar path: start ISO of each BAT segment in the booking block (multi-tile highlight).
         * Buffer-candidate path: one tile per full block only.
         */
        function timeSlotHighlightStartIsos(startIso) {
          if (!startIso || !selectedYmd) {
            return new Set();
          }
          if (bufferMinutes > 0 && s.slotCandidatesUrl && bufferSlotCandidates !== null) {
            const ok = bufferSlotCandidates.some((c) => c && c.start === startIso);
            return new Set(ok ? [startIso] : []);
          }
          const sport = currentSport();
          if (!sport || !calendars) {
            return new Set([startIso]);
          }
          const openM = parseHmMinutes(bookingDayStart);
          const closeM = parseHmMinutes(bookingDayEnd);
          const hasWindow = openM !== null && closeM !== null && closeM > openM;
          const tz = siteTimeZoneId;
          for (const v of sport.variations) {
            if (isVariationClosedOnYmd(v.id, selectedYmd)) {
              continue;
            }
            const n = slotCountForVariation(v, slotMinutesDefault, playMinutes);
            if (!n) {
              continue;
            }
            const requiredN =
              bufferMinutes > 0
                ? blockSlotCountForVariation(v, slotMinutesDefault, playMinutes, bufferMinutes)
                : n;
            if (!requiredN) {
              continue;
            }
            if (!matchesStaggeredStart(startIso, tz, openM, hasWindow, bufferMinutes, playMinutes)) {
              continue;
            }
            const starts = consecutiveBlockStarts(calendars[v.id], startIso, requiredN);
            if (!starts) {
              continue;
            }
            const rentalEnd = playBufferEndIso(startIso, playMinutes, bufferMinutes);
            if (!rentalEnd || !slotFitsBookingWindow(startIso, rentalEnd, tz, hasWindow, openM, closeM)) {
              continue;
            }
            return new Set(starts);
          }
          return new Set([startIso]);
        }

        function applyTimeSlotHighlights() {
          const highlights = timeSlotHighlightStartIsos(selectedStartIso);
          if (!elTimes) {
            return;
          }
          elTimes.querySelectorAll('button').forEach((b) => {
            if (b.disabled) {
              return;
            }
            const on = highlights.has(b.dataset.startIso);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
            b.className = timeSlotPillClasses(on, false);
          });
        }

        function closureForVariationOnYmd(variationId, ymd) {
          const ranges = resourceClosuresByVariation[String(variationId)];
          if (!Array.isArray(ranges)) {
            return null;
          }
          for (const range of ranges) {
            const startDate = String(range.startDate || '');
            const endDate = String(range.endDate || '');
            if (!startDate || !endDate) {
              continue;
            }
            if (ymd >= startDate && ymd <= endDate) {
              return {
                reason: String(range.reason || ''),
              };
            }
          }
          return null;
        }

        function isVariationClosedOnYmd(variationId, ymd) {
          return closureForVariationOnYmd(variationId, ymd) !== null;
        }

        async function fetchCalendar(vid, fromIso, toIso) {
          const interval = encodeURIComponent(s.slotInterval || 'PT60M');
          const url = `${Drupal.url(`commerce-bat/availability/${vid}`)}?from=${encodeURIComponent(
            fromIso,
          )}&to=${encodeURIComponent(toIso)}&interval=${interval}`;
          const res = await fetch(url, { credentials: 'same-origin' });
          if (!res.ok) {
            throw new Error(`Availability HTTP ${res.status}`);
          }
          return res.json();
        }

        /**
         * @param {string} ymd
         * @param {number[]} variationIds
         * @returns {Promise<Array<{start: string, end: string, variationIds: number[]}>>}
         */
        async function fetchBufferSlotCandidates(ymd, variationIds) {
          const url = s.slotCandidatesUrl;
          if (!url) {
            return [];
          }
          const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': s.csrfToken,
            },
            body: JSON.stringify({
              ymd,
              duration_minutes: playMinutes,
              variation_ids: variationIds,
              quantity: 1,
            }),
          });
          if (!res.ok) {
            throw new Error(`Slot candidates HTTP ${res.status}`);
          }
          const data = await res.json();
          const slots = Array.isArray(data.slots) ? data.slots : [];
          return slots;
        }

        async function loadDayData() {
          const sport = currentSport();
          if (!sport || !selectedDay) {
            return;
          }
          const openVariations = sport.variations.filter((v) => !isVariationClosedOnYmd(v.id, selectedDay.ymd));
          if (!openVariations.length) {
            calendars = {};
            bufferSlotCandidates = [];
            elTimes.innerHTML = '';
            elCourts.innerHTML = '';
            setBookingFlowSectionsVisible(false);
            setStatus(Drupal.t('All courts are temporarily closed on this date.'));
            return;
          }
          setBookingFlowSectionsVisible(true);
          setStatus(Drupal.t('Loading availability…'));
          elTimes.innerHTML = '';
          elCourts.innerHTML = '';
          const fromIso = selectedDay.from;
          const toIso = selectedDay.to;
          const useCandidates = bufferMinutes > 0 && s.slotCandidatesUrl;

          try {
            if (useCandidates) {
              calendars = null;
              bufferSlotCandidates = await fetchBufferSlotCandidates(
                selectedDay.ymd,
                openVariations.map((v) => v.id),
              );
            } else {
              bufferSlotCandidates = null;
              const results = await Promise.all(
                openVariations.map(async (v) => {
                  const cal = await fetchCalendar(v.id, fromIso, toIso);
                  return [String(v.id), cal];
                }),
              );
              calendars = Object.fromEntries(results);
            }
            renderTimesForDay();
            setStatus('');
          } catch (e) {
            setBookingFlowSectionsVisible(true);
            setStatus(Drupal.t('Could not load availability. Please refresh.'));
            if (useCandidates) {
              bufferSlotCandidates = null;
              calendars = null;
            } else {
              calendars = null;
              bufferSlotCandidates = null;
            }
            refreshPitchSection();
            // eslint-disable-next-line no-console
            console.error(e);
          }
        }

        function renderTimesForDay() {
          elTimes.innerHTML = '';
          if (!selectedYmd) {
            refreshPitchSection();
            return;
          }
          const tz = siteTimeZoneId;
          if (bufferMinutes > 0 && s.slotCandidatesUrl && bufferSlotCandidates !== null) {
            const nowMins = minutesNowInZoneForYmd(selectedYmd, tz);
            const sameDayClosed = sameDayCutoffMins !== null && nowMins >= 0 && nowMins > sameDayCutoffMins;
            const isToday = selectedYmd === todayYmd(tz);
            if (!bufferSlotCandidates.length) {
              const p = document.createElement('p');
              p.className = 'text-sm text-slate-500';
              p.textContent = Drupal.t('No time slots for this day.');
              elTimes.appendChild(p);
              refreshPitchSection();
              return;
            }
            if (sameDayClosed) {
              const p = document.createElement('p');
              p.className = 'text-sm text-slate-500';
              p.textContent = Drupal.t('Same-day booking is closed after @time.', { '@time': sameDayCutoffHmDisplay });
              elTimes.appendChild(p);
              refreshPitchSection();
              return;
            }
            let slots = bufferSlotCandidates
              .filter((slot) => slot && slot.start && slot.end && Array.isArray(slot.variationIds) && slot.variationIds.length)
              .slice()
              .sort((a, b) => String(a.start).localeCompare(String(b.start)));
            slots = slots.filter((slot) => {
              if (isToday && sameDayCutoffMins !== null) {
                const startM = minutesSinceMidnightInZone(slot.start, tz);
                if (startM >= sameDayCutoffMins) {
                  return false;
                }
              }
              return true;
            });
            if (!slots.length) {
              const p = document.createElement('p');
              p.className = 'text-sm text-slate-500';
              p.textContent = Drupal.t(
                'No open times match your opening time, buffer spacing on the lesson grid, or booking window. Try another date or adjust booking hours.',
              );
              elTimes.appendChild(p);
              refreshPitchSection();
              return;
            }
            slots.forEach((slot) => {
              const btn = document.createElement('button');
              btn.type = 'button';
              btn.setAttribute('role', 'listitem');
              btn.setAttribute('aria-pressed', 'false');

              const startM = minutesSinceMidnightInZone(slot.start, tz);
              const isPast = isToday && nowMins >= 0 && startM <= nowMins;

              setTimeSlotPillContent(btn, slot.start, slot.end, tz);

              btn.dataset.startIso = slot.start;
              btn.className = timeSlotPillClasses(false, isPast);

              if (isPast) {
                btn.disabled = true;
                btn.setAttribute('aria-disabled', 'true');
                elTimes.appendChild(btn);
                return;
              }

              btn.addEventListener('click', () => {
                selectedStartIso = slot.start;
                applyTimeSlotHighlights();
                refreshPitchSection();
              });

              elTimes.appendChild(btn);
            });
            if (selectedStartIso) {
              applyTimeSlotHighlights();
            }
            refreshPitchSection();
            return;
          }

          if (!calendars) {
            refreshPitchSection();
            return;
          }
          const byStart = new Map();
          Object.keys(calendars).forEach((vid) => {
            calendarEntries(calendars[vid]).forEach((entry) => {
              if (!entry.start) {
                return;
              }
              if (ymdInZone(entry.start, tz) !== selectedYmd) {
                return;
              }
              if (!byStart.has(entry.start)) {
                byStart.set(entry.start, []);
              }
              byStart.get(entry.start).push({ vid, entry });
            });
          });
          const times = Array.from(byStart.keys()).sort();
          const openM = parseHmMinutes(bookingDayStart);
          const closeM = parseHmMinutes(bookingDayEnd);
          const hasWindow = openM !== null && closeM !== null && closeM > openM;

          const sport = currentSport();
          const nowMins = minutesNowInZoneForYmd(selectedYmd, tz);
          const sameDayClosed = sameDayCutoffMins !== null && nowMins >= 0 && nowMins > sameDayCutoffMins;
          const isToday = selectedYmd === todayYmd(tz);
          const timesInWindow = times.filter((startIso) => {
            if (isToday && sameDayCutoffMins !== null) {
              const startM = minutesSinceMidnightInZone(startIso, tz);
              if (startM >= sameDayCutoffMins) {
                return false;
              }
            }
            if (sameDayClosed) {
              return false;
            }
            const row = byStart.get(startIso) || [];
            const sample = row.find(({ entry }) => entry && entry.end)?.entry;
            if (!sample || !sample.end) {
              return false;
            }
            if (!hasWindow) {
              /* still need multi-slot fit below */
            } else {
              const startM = minutesSinceMidnightInZone(startIso, tz);
              const endM = minutesSinceMidnightInZone(sample.end, tz);
              if (endM < startM) {
                return false;
              }
              if (!(startM >= openM && endM <= closeM)) {
                return false;
              }
            }
            if (!sport) {
              return false;
            }
            for (const v of sport.variations) {
              if (isVariationClosedOnYmd(v.id, selectedYmd)) {
                continue;
              }
              const n = slotCountForVariation(v, slotMinutesDefault, playMinutes);
              if (!n) {
                continue;
              }
              const requiredN =
                bufferMinutes > 0
                  ? blockSlotCountForVariation(v, slotMinutesDefault, playMinutes, bufferMinutes)
                  : n;
              if (!requiredN) {
                continue;
              }
              if (!matchesStaggeredStart(startIso, tz, openM, hasWindow, bufferMinutes, playMinutes)) {
                continue;
              }
              const availabilityBlock = consecutiveBlock(calendars[v.id], startIso, requiredN);
              if (!availabilityBlock) {
                continue;
              }
              const rentalEnd = playBufferEndIso(startIso, playMinutes, bufferMinutes);
              if (!rentalEnd || !slotFitsBookingWindow(startIso, rentalEnd, tz, hasWindow, openM, closeM)) {
                continue;
              }
              return true;
            }
            return false;
          });

          if (!times.length) {
            const p = document.createElement('p');
            p.className = 'text-sm text-slate-500';
            p.textContent = Drupal.t('No time slots for this day.');
            elTimes.appendChild(p);
            refreshPitchSection();
            return;
          }

          if (sameDayClosed) {
            const p = document.createElement('p');
            p.className = 'text-sm text-slate-500';
            p.textContent = Drupal.t('Same-day booking is closed after @time.', { '@time': sameDayCutoffHmDisplay });
            elTimes.appendChild(p);
            refreshPitchSection();
            return;
          }

          if (!timesInWindow.length) {
            const p = document.createElement('p');
            p.className = 'text-sm text-slate-500';
            if (bufferMinutes > 0 && sport) {
              p.textContent = Drupal.t(
                'No open times match your opening time, buffer spacing on the lesson grid, or booking window. Try another date or adjust booking hours.',
              );
            } else {
              p.textContent = Drupal.t('No slots for this duration within your booking hours.');
            }
            elTimes.appendChild(p);
            refreshPitchSection();
            return;
          }

          timesInWindow.forEach((startIso) => {
            const row = byStart.get(startIso) || [];
            const hasSelectable = row.some(({ entry }) => isEntrySelectable(entry));
            const rentalEnd = playBufferEndIso(startIso, playMinutes, bufferMinutes);

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.setAttribute('role', 'listitem');
            btn.setAttribute('aria-pressed', 'false');

            const startM = minutesSinceMidnightInZone(startIso, tz);
            const isPast = isToday && nowMins >= 0 && startM <= nowMins;

            setTimeSlotPillContent(btn, startIso, rentalEnd || '', tz);

            btn.dataset.startIso = startIso;

            if (!hasSelectable || isPast) {
              btn.disabled = true;
              btn.className = timeSlotPillClasses(false, true);
              btn.setAttribute('aria-disabled', 'true');
              btn.title = Drupal.t('Fully booked or past time.');
              elTimes.appendChild(btn);
              return;
            }

            btn.className = timeSlotPillClasses(false, false);

            btn.addEventListener('click', () => {
              selectedStartIso = startIso;
              applyTimeSlotHighlights();
              refreshPitchSection();
            });

            elTimes.appendChild(btn);
          });
          if (selectedStartIso) {
            applyTimeSlotHighlights();
          }
          refreshPitchSection();
        }

        /**
         * All pitches for the current sport when no time slot is chosen (or after duration/date change).
         * Resource closures still hide pitches on the selected bookable date.
         */
        function renderCourtsOverview() {
          elCourts.innerHTML = '';
          const sport = currentSport();
          if (!sport || !Array.isArray(sport.variations) || !sport.variations.length) {
            return;
          }
          let list = sport.variations.slice();
          if (selectedYmd && bookableYmd.has(selectedYmd)) {
            list = list.filter((v) => !isVariationClosedOnYmd(v.id, selectedYmd));
          }
          if (!list.length) {
            const p = document.createElement('p');
            p.className = 'text-sm text-slate-500';
            p.textContent = Drupal.t('No pitches to show for this sport on the selected date.');
            elCourts.appendChild(p);
            return;
          }
          list.forEach((v) => {
            const card = document.createElement('div');
            card.setAttribute('role', 'listitem');
            const highlight = s.initialVariationId && String(s.initialVariationId) === String(v.id);
            card.className = [
              'flex flex-col overflow-hidden rounded-2xl border bg-white shadow-sm',
              highlight ? 'border-[#02216E] ring-2 ring-[#02216E] ring-offset-2' : 'border-slate-200',
            ].join(' ');

            const media = document.createElement('div');
            media.className = 'aspect-[4/3] w-full bg-slate-100 bg-cover bg-center';
            if (v.image) {
              media.style.backgroundImage = `url('${String(v.image).replace(/'/g, '%27')}')`;
            }

            const body = document.createElement('div');
            body.className = 'flex flex-1 flex-col gap-2 p-4';

            const h = document.createElement('h3');
            h.className = 'font-display text-lg font-semibold text-slate-900';
            h.textContent = v.title;

            const price = document.createElement('p');
            price.className = 'text-sm font-semibold text-orange-600';
            price.textContent = formatPriceForDuration(v, playMinutes, slotMinutesDefault);

            const buffer = document.createElement('p');
            buffer.className = 'text-xs text-slate-500';
            buffer.textContent = formatOptionalBufferCaption(bufferMinutes);

            if (v.detailUrl) {
              const detail = document.createElement('a');
              detail.href = String(v.detailUrl);
              detail.className =
                'text-sm font-semibold text-[#02216E] underline hover:no-underline focus:outline-none focus-visible:ring-2 focus-visible:ring-[#02216E]';
              detail.textContent = Drupal.t('View details');
              body.appendChild(detail);
            }

            const bookBtn = document.createElement('button');
            bookBtn.type = 'button';
            bookBtn.disabled = true;
            bookBtn.className =
              'cb-book mt-2 inline-flex cursor-not-allowed items-center justify-center rounded-xl border border-slate-200 bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-400';
            bookBtn.textContent = Drupal.t('Book');

            body.appendChild(h);
            body.appendChild(price);
            if (buffer.textContent) {
              body.appendChild(buffer);
            }
            body.appendChild(bookBtn);
            card.appendChild(media);
            card.appendChild(body);
            elCourts.appendChild(card);
          });
        }

        function refreshPitchSection() {
          if (!elCourts) {
            return;
          }
          if (selectedStartIso && selectedYmd) {
            renderCourts(selectedStartIso);
          } else {
            renderCourtsOverview();
          }
        }

        function renderCourts(startIso) {
          elCourts.innerHTML = '';
          if (!startIso || !selectedYmd) {
            return;
          }
          const sport = currentSport();
          if (!sport) {
            return;
          }
          if (bufferMinutes > 0 && s.slotCandidatesUrl && bufferSlotCandidates && bufferSlotCandidates.length) {
            const slot = bufferSlotCandidates.find((c) => c.start === startIso);
            if (!slot || !Array.isArray(slot.variationIds) || !slot.variationIds.length) {
              const p = document.createElement('p');
              p.className = 'text-sm text-slate-500';
              p.textContent = Drupal.t('No courts available for this time and date (temporary closure or no availability).');
              elCourts.appendChild(p);
              return;
            }
            const blocks = [];
            slot.variationIds.forEach((vidRaw) => {
              const v = sport.variations.find((x) => String(x.id) === String(vidRaw));
              if (!v || isVariationClosedOnYmd(v.id, selectedYmd)) {
                return;
              }
              blocks.push({ v, block: { start: slot.start, end: slot.end } });
            });
            if (!blocks.length) {
              const p = document.createElement('p');
              p.className = 'text-sm text-slate-500';
              p.textContent = Drupal.t('No courts available for this time and date (temporary closure or no availability).');
              elCourts.appendChild(p);
              return;
            }
            blocks.forEach(({ v, block }) => {
              const addVariationToCart = async (triggerEl) => {
                setStatus('');
                if (triggerEl) {
                  triggerEl.disabled = true;
                }
                try {
                  const res = await fetch(s.addUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                      'Content-Type': 'application/json',
                      'X-CSRF-Token': s.csrfToken,
                    },
                    body: JSON.stringify({
                      variation_id: v.id,
                      start: block.start,
                      end: block.end,
                      quantity: 1,
                    }),
                  });
                  const data = await res.json().catch(() => ({}));
                  if (!res.ok) {
                    setStatus(data.message || Drupal.t('Booking failed.'));
                    if (triggerEl) {
                      triggerEl.disabled = false;
                    }
                    return;
                  }
                  if (data.redirect) {
                    window.location.href = data.redirect;
                  }
                } catch (err) {
                  setStatus(Drupal.t('Network error. Try again.'));
                  if (triggerEl) {
                    triggerEl.disabled = false;
                  }
                  // eslint-disable-next-line no-console
                  console.error(err);
                }
              };

              const card = document.createElement('div');
              card.setAttribute('role', 'listitem');
              const highlight = s.initialVariationId && String(s.initialVariationId) === String(v.id);
              card.className = [
                'flex cursor-pointer flex-col overflow-hidden rounded-2xl border bg-white shadow-sm',
                highlight ? 'border-[#02216E] ring-2 ring-[#02216E] ring-offset-2' : 'border-slate-200',
              ].join(' ');
              card.setAttribute('tabindex', '0');
              card.setAttribute('aria-label', Drupal.t('Book @title', { '@title': v.title }));

              const media = document.createElement('div');
              media.className = 'aspect-[4/3] w-full bg-slate-100 bg-cover bg-center';
              if (v.image) {
                media.style.backgroundImage = `url('${String(v.image).replace(/'/g, '%27')}')`;
              }

              const body = document.createElement('div');
              body.className = 'flex flex-1 flex-col gap-2 p-4';

              const h = document.createElement('h3');
              h.className = 'font-display text-lg font-semibold text-slate-900';
              h.textContent = v.title;

              const price = document.createElement('p');
              price.className = 'text-sm font-semibold text-orange-600';
              price.textContent = formatPriceForDuration(v, playMinutes, slotMinutesDefault);

              const buffer = document.createElement('p');
              buffer.className = 'text-xs text-slate-500';
              buffer.textContent = formatOptionalBufferCaption(bufferMinutes);

              const bookBtn = document.createElement('button');
              bookBtn.type = 'button';
              bookBtn.className =
                'cb-book mt-2 inline-flex items-center justify-center rounded-xl bg-[#02216E] px-4 py-2 text-sm font-semibold text-white hover:bg-[#011550] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#02216E]';
              bookBtn.textContent = Drupal.t('Book');

              bookBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                addVariationToCart(bookBtn);
              });

              card.addEventListener('click', () => {
                addVariationToCart(bookBtn);
              });
              card.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                  e.preventDefault();
                  addVariationToCart(bookBtn);
                }
              });

              body.appendChild(h);
              body.appendChild(price);
              if (buffer.textContent) {
                body.appendChild(buffer);
              }
              body.appendChild(bookBtn);
              card.appendChild(media);
              card.appendChild(body);
              elCourts.appendChild(card);
            });
            return;
          }

          if (!calendars) {
            return;
          }
          const openM = parseHmMinutes(bookingDayStart);
          const closeM = parseHmMinutes(bookingDayEnd);
          const hasWindow = openM !== null && closeM !== null && closeM > openM;
          const blocks = [];
          sport.variations.forEach((v) => {
            if (isVariationClosedOnYmd(v.id, selectedYmd)) {
              return;
            }
            const n = slotCountForVariation(v, slotMinutesDefault, playMinutes);
            if (!n) {
              return;
            }
            const requiredN =
              bufferMinutes > 0
                ? blockSlotCountForVariation(v, slotMinutesDefault, playMinutes, bufferMinutes)
                : n;
            if (!requiredN) {
              return;
            }
            if (!matchesStaggeredStart(startIso, siteTimeZoneId, openM, hasWindow, bufferMinutes, playMinutes)) {
              return;
            }
            const availabilityBlock = consecutiveBlock(calendars[v.id], startIso, requiredN);
            if (!availabilityBlock) {
              return;
            }
            const rentalEnd = playBufferEndIso(startIso, playMinutes, bufferMinutes);
            if (!rentalEnd || !slotFitsBookingWindow(startIso, rentalEnd, siteTimeZoneId, hasWindow, openM, closeM)) {
              return;
            }
            blocks.push({ v, block: availabilityBlock });
          });

          if (!blocks.length) {
            const p = document.createElement('p');
            p.className = 'text-sm text-slate-500';
            p.textContent = Drupal.t('No courts available for this time and date (temporary closure or no availability).');
            elCourts.appendChild(p);
            return;
          }

          blocks.forEach(({ v, block }) => {
            const addVariationToCart = async (triggerEl) => {
              setStatus('');
              if (triggerEl) {
                triggerEl.disabled = true;
              }
              try {
                const res = await fetch(s.addUrl, {
                  method: 'POST',
                  credentials: 'same-origin',
                  headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': s.csrfToken,
                  },
                  body: JSON.stringify({
                    variation_id: v.id,
                    start: block.start,
                    end: playBufferEndIso(block.start, playMinutes, bufferMinutes),
                    quantity: 1,
                  }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                  setStatus(data.message || Drupal.t('Booking failed.'));
                  if (triggerEl) {
                    triggerEl.disabled = false;
                  }
                  return;
                }
                if (data.redirect) {
                  window.location.href = data.redirect;
                }
              } catch (err) {
                setStatus(Drupal.t('Network error. Try again.'));
                if (triggerEl) {
                  triggerEl.disabled = false;
                }
                // eslint-disable-next-line no-console
                console.error(err);
              }
            };

            const card = document.createElement('div');
            card.setAttribute('role', 'listitem');
            const highlight = s.initialVariationId && String(s.initialVariationId) === String(v.id);
            card.className = [
              'flex cursor-pointer flex-col overflow-hidden rounded-2xl border bg-white shadow-sm',
              highlight ? 'border-[#02216E] ring-2 ring-[#02216E] ring-offset-2' : 'border-slate-200',
            ].join(' ');
            card.setAttribute('tabindex', '0');
            card.setAttribute('aria-label', Drupal.t('Book @title', { '@title': v.title }));

            const media = document.createElement('div');
            media.className = 'aspect-[4/3] w-full bg-slate-100 bg-cover bg-center';
            if (v.image) {
              media.style.backgroundImage = `url('${String(v.image).replace(/'/g, '%27')}')`;
            }

            const body = document.createElement('div');
            body.className = 'flex flex-1 flex-col gap-2 p-4';

            const h = document.createElement('h3');
            h.className = 'font-display text-lg font-semibold text-slate-900';
            h.textContent = v.title;

            const price = document.createElement('p');
            price.className = 'text-sm font-semibold text-orange-600';
            price.textContent = formatPriceForDuration(v, playMinutes, slotMinutesDefault);

            const buffer = document.createElement('p');
            buffer.className = 'text-xs text-slate-500';
            buffer.textContent = formatOptionalBufferCaption(bufferMinutes);

            const bookBtn = document.createElement('button');
            bookBtn.type = 'button';
            bookBtn.className =
              'cb-book mt-2 inline-flex items-center justify-center rounded-xl bg-[#02216E] px-4 py-2 text-sm font-semibold text-white hover:bg-[#011550] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#02216E]';
            bookBtn.textContent = Drupal.t('Book');

            bookBtn.addEventListener('click', (e) => {
              e.preventDefault();
              e.stopPropagation();
              addVariationToCart(bookBtn);
            });

            card.addEventListener('click', () => {
              addVariationToCart(bookBtn);
            });
            card.addEventListener('keydown', (e) => {
              if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                addVariationToCart(bookBtn);
              }
            });

            body.appendChild(h);
            body.appendChild(price);
            if (buffer.textContent) {
              body.appendChild(buffer);
            }
            body.appendChild(bookBtn);
            card.appendChild(media);
            card.appendChild(body);
            elCourts.appendChild(card);
          });
        }

        function renderModalCalendar() {
          if (!elCalGrid || !elCalMonthLabel) {
            return;
          }
          const { year, month0 } = modalView;
          elCalMonthLabel.textContent = modalMonthLongLabel(year, month0).toUpperCase();
          elCalGrid.innerHTML = '';

          const first = new Date(Date.UTC(year, month0, 1));
          let startWeekday = first.getUTCDay();
          startWeekday = (startWeekday - firstDayOfWeek + 7) % 7;
          const daysInMo = new Date(Date.UTC(year, month0 + 1, 0)).getUTCDate();

          for (let i = 0; i < startWeekday; i++) {
            const cell = document.createElement('div');
            cell.className = 'h-10';
            elCalGrid.appendChild(cell);
          }

          for (let d = 1; d <= daysInMo; d++) {
            const ymd = `${year}-${String(month0 + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = String(d);
            btn.className =
              'h-10 rounded-lg text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-[#02216E]';
            const bookable = bookableYmd.has(ymd);
            const isPending = modalPendingYmd === ymd;
            if (!bookable) {
              btn.disabled = true;
              btn.className += ' cursor-not-allowed text-slate-300';
            } else if (isPending) {
              btn.className += ' bg-[#02216E] text-white';
            } else {
              btn.className += ' text-slate-800 hover:bg-sky-50';
            }
            btn.addEventListener('click', () => {
              if (!bookable) {
                return;
              }
              modalPendingYmd = ymd;
              renderModalCalendar();
            });
            elCalGrid.appendChild(btn);
          }
        }

        function openModal() {
          if (!elModal) {
            return;
          }
          focusBeforeModal = document.activeElement;
          if (selectedYmd) {
            const [y, m] = selectedYmd.split('-').map((x) => parseInt(x, 10));
            modalView = { year: y, month0: m - 1 };
          } else if (visibleYm) {
            const [y, m] = visibleYm.split('-').map((x) => parseInt(x, 10));
            modalView = { year: y, month0: m - 1 };
          }
          modalPendingYmd = selectedYmd;
          renderModalCalendar();
          elModal.classList.remove('hidden');
          elModal.setAttribute('aria-hidden', 'false');
          const focusables = getModalFocusables();
          if (focusables.length) {
            focusables[0].focus();
          }
          document.addEventListener('keydown', onModalKeydown);
        }

        function closeModal() {
          if (!elModal) {
            return;
          }
          elModal.classList.add('hidden');
          elModal.setAttribute('aria-hidden', 'true');
          document.removeEventListener('keydown', onModalKeydown);
          if (focusBeforeModal && typeof focusBeforeModal.focus === 'function') {
            focusBeforeModal.focus();
          }
          focusBeforeModal = null;
        }

        function getModalFocusables() {
          if (!elModalDialog) {
            return [];
          }
          return Array.from(
            elModalDialog.querySelectorAll(
              'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
            ),
          );
        }

        function onModalKeydown(e) {
          if (e.key === 'Escape') {
            e.preventDefault();
            closeModal();
            return;
          }
          if (e.key !== 'Tab' || !elModalDialog) {
            return;
          }
          const list = getModalFocusables();
          if (!list.length) {
            return;
          }
          const first = list[0];
          const last = list[list.length - 1];
          if (e.shiftKey) {
            if (document.activeElement === first) {
              e.preventDefault();
              last.focus();
            }
          } else if (document.activeElement === last) {
            e.preventDefault();
            first.focus();
          }
        }

        if (elStripPrev) {
          elStripPrev.addEventListener('click', () => {
            if (!visibleYm) {
              return;
            }
            visibleYm = addMonthsYm(visibleYm, -1);
            const slice = daysInVisibleMonth();
            selectedYmd = null;
            selectedDay = null;
            selectedStartIso = null;
            calendars = null;
            elTimes.innerHTML = '';
            elCourts.innerHTML = '';
            renderDateStrip();
            updateMonthYearLabel();
            if (slice.length) {
              selectDay(firstBookableDayInMonth(visibleYm));
            }
          });
        }

        if (elStripNext) {
          elStripNext.addEventListener('click', () => {
            if (!visibleYm) {
              return;
            }
            visibleYm = addMonthsYm(visibleYm, 1);
            const slice = daysInVisibleMonth();
            selectedYmd = null;
            selectedDay = null;
            selectedStartIso = null;
            calendars = null;
            elTimes.innerHTML = '';
            elCourts.innerHTML = '';
            renderDateStrip();
            updateMonthYearLabel();
            if (slice.length) {
              selectDay(firstBookableDayInMonth(visibleYm));
            }
          });
        }

        const DATE_SCROLL_PX = 300;
        if (elDatesPrev) {
          elDatesPrev.addEventListener('click', () => {
            elDates.scrollBy({ left: -DATE_SCROLL_PX, behavior: 'smooth' });
          });
        }
        if (elDatesNext) {
          elDatesNext.addEventListener('click', () => {
            elDates.scrollBy({ left: DATE_SCROLL_PX, behavior: 'smooth' });
          });
        }
        let _dateScrollTimer = null;
        elDates.addEventListener('scroll', () => {
          if (_dateScrollTimer) {
            clearTimeout(_dateScrollTimer);
          }
          _dateScrollTimer = setTimeout(updateDateNavButtons, 80);
        }, { passive: true });

        if (elCalOpen) {
          elCalOpen.addEventListener('click', () => openModal());
        }
        if (elCalClose) {
          elCalClose.addEventListener('click', () => closeModal());
        }
        if (elModalBackdrop) {
          elModalBackdrop.addEventListener('click', () => closeModal());
        }
        if (elCalPrev) {
          elCalPrev.addEventListener('click', () => {
            const d = new Date(Date.UTC(modalView.year, modalView.month0 - 1, 1));
            modalView = { year: d.getUTCFullYear(), month0: d.getUTCMonth() };
            renderModalCalendar();
          });
        }
        if (elCalNext) {
          elCalNext.addEventListener('click', () => {
            const d = new Date(Date.UTC(modalView.year, modalView.month0 + 1, 1));
            modalView = { year: d.getUTCFullYear(), month0: d.getUTCMonth() };
            renderModalCalendar();
          });
        }
        if (elCalApply) {
          elCalApply.addEventListener('click', () => {
            if (!modalPendingYmd) {
              return;
            }
            const day = dates.find((x) => x.ymd === modalPendingYmd);
            if (!day) {
              return;
            }
            visibleYm = modalPendingYmd.slice(0, 7);
            closeModal();
            selectDay(day);
          });
        }

        if (elDuration) {
          elDuration.addEventListener('change', () => {
            playMinutes = Math.max(1, parseInt(elDuration.value, 10) || 60);
            selectedStartIso = null;
            elCourts.innerHTML = '';
            if (selectedYmd && selectedDay && (calendars || (bufferMinutes > 0 && s.slotCandidatesUrl))) {
              if (bufferMinutes > 0 && s.slotCandidatesUrl) {
                loadDayData();
              } else if (calendars) {
                renderTimesForDay();
              }
            }
          });
        }

        renderSports();
        if (dates.length) {
          playMinutes = Math.max(1, parseInt(elDuration?.value, 10) || 60);
          const today = todayYmd(siteTimeZoneId);
          if (bookableYmd.has(today)) {
            visibleYm = today.slice(0, 7);
          } else {
            visibleYm = dates[0].ymd.slice(0, 7);
          }
          renderDateStrip();
          updateMonthYearLabel();
          const pick = bookableYmd.has(today)
            ? dates.find((d) => d.ymd === today)
            : firstBookableDayInMonth(visibleYm);
          if (pick) {
            selectDay(pick);
          } else {
            setStatus(Drupal.t('No bookable dates in the current range.'));
          }
        } else {
          renderDateStrip();
          updateMonthYearLabel();
        }
      });
    },
  };
})(Drupal, drupalSettings, once);
