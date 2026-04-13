/**
 * @file
 * Cart: edit BAT lesson slot (modal + availability + POST update).
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

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

  function parseHmMinutes(hm) {
    const raw = String(hm || '').trim();
    if (!/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(raw)) {
      return null;
    }
    const [h, min] = raw.split(':').map((x) => parseInt(x, 10));
    return h * 60 + min;
  }

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

  function isEntrySelectable(entry) {
    if (!entry) {
      return false;
    }
    if (entry.status === 'closed' || entry.booking_window_exceeded) {
      return false;
    }
    return entry.status === 'available' && Number(entry.remaining) > 0;
  }

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
   *
   * @returns {string}
   */
  function interfaceIntlLocale() {
    if (memoResolvedIntlLocale !== null) {
      return memoResolvedIntlLocale;
    }
    const s = drupalSettings.courtBookingCart;
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

  function calendarEntries(cal) {
    if (!cal || typeof cal !== 'object') {
      return [];
    }
    return Object.values(cal);
  }

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

  function slotCountForVariation(v, slotMinutesDefault, playMinutes) {
    const slotLen = Math.max(1, Number(v.slotMinutes) || slotMinutesDefault);
    const totalMin = Math.max(0, Number(playMinutes) || 0);
    if (totalMin % slotLen !== 0) {
      return null;
    }
    return totalMin / slotLen;
  }

  function blockSlotCountForVariation(v, slotMinutesDefault, playMinutes, bufferMinutes) {
    if (!bufferMinutes || bufferMinutes <= 0) {
      return null;
    }
    const slotLen = Math.max(1, Number(v.slotMinutes) || slotMinutesDefault);
    const playMin = Math.max(0, Number(playMinutes) || 0);
    return Math.ceil((playMin + bufferMinutes) / slotLen);
  }

  function matchesStaggeredStart(
    startIso,
    timeZone,
    openM,
    hasWindow,
    bufferMinutes,
    playMinutes,
    baseStepMinutes,
  ) {
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
    const baseStep = Math.max(1, Number(baseStepMinutes) || 60);
    if (hasWindow && openM !== null && startM < openM) {
      return false;
    }
    return (startM - anchor) % baseStep === 0;
  }

  function playBufferEndIso(startIso, playMinutes, bufferMinutes) {
    const ms = new Date(startIso).getTime();
    if (Number.isNaN(ms)) {
      return '';
    }
    const addMin = Math.max(0, Number(playMinutes) || 0) + Math.max(0, bufferMinutes);
    return new Date(ms + addMin * 60000).toISOString();
  }

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

  function formatSlotRangeLabel(startIso, blockEndIso, timeZone) {
    const opts = { hour: 'numeric', minute: '2-digit', timeZone };
    const loc = interfaceIntlLocale();
    const a = new Date(startIso).toLocaleTimeString(loc, opts);
    const b = new Date(blockEndIso).toLocaleTimeString(loc, opts);
    return `${a} – ${b}`;
  }

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

  Drupal.behaviors.courtBookingCartSlot = {
    attach(context) {
      const s = drupalSettings.courtBookingCart;
      if (!s || !Array.isArray(s.dates) || !s.variationsById) {
        return;
      }

      once('court-booking-cart-slot', 'body', context).forEach(() => {
        const siteTimeZoneId = normalizeDrupalTimeZoneId(s.timezone) || 'UTC';
        const slotMinutesDefault = Math.max(1, parseInt(String(s.slotMinutes || 60), 10));
        const durationGridMinutesCart = Math.max(1, parseInt(String(s.durationGridMinutes || slotMinutesDefault), 10));
        let bufferMinutes = Math.max(0, parseInt(String(s.bufferMinutes || 0), 10));
        let sameDayCutoffMins = parseHmMinutes(s.sameDayCutoffHm);
        let sameDayCutoffHmDisplay = String(s.sameDayCutoffHm || '');
        let maxBookingHours = Math.max(1, Math.min(24, parseInt(String(s.maxBookingHours || 4), 10)));
        let dates = Array.isArray(s.dates) ? s.dates.map((d) => ({ ...d })) : [];
        let blackoutYmd = new Set(Array.isArray(s.blackoutDates) ? s.blackoutDates.map((d) => String(d)) : []);
        let resourceClosuresByVariation =
          s.resourceClosuresByVariation && typeof s.resourceClosuresByVariation === 'object'
            ? s.resourceClosuresByVariation
            : {};
        let bookingDayStart = String(s.bookingDayStart || '06:00');
        let bookingDayEnd = String(s.bookingDayEnd || '23:00');
        /** @type {Set<string>} */
        let bookableYmd = new Set();
        const variationsById = s.variationsById;

        function rebuildBookableYmd() {
          bookableYmd = new Set(dates.map((d) => d.ymd).filter((ymd) => !blackoutYmd.has(ymd)));
        }

        /**
         * Apply per-variation merged booking rules from PHP, or root cart settings.
         *
         * @param {object|null|undefined} v
         */
        function applyVariationBookingRules(v) {
          const b = v && v.booking ? v.booking : null;
          if (!b) {
            dates = Array.isArray(s.dates) ? s.dates.map((x) => ({ ...x })) : [];
            blackoutYmd = new Set(Array.isArray(s.blackoutDates) ? s.blackoutDates.map((d) => String(d)) : []);
            resourceClosuresByVariation =
              s.resourceClosuresByVariation && typeof s.resourceClosuresByVariation === 'object'
                ? s.resourceClosuresByVariation
                : {};
            bufferMinutes = Math.max(0, parseInt(String(s.bufferMinutes || 0), 10));
            sameDayCutoffMins = parseHmMinutes(s.sameDayCutoffHm);
            sameDayCutoffHmDisplay = String(s.sameDayCutoffHm || '');
            maxBookingHours = Math.max(1, Math.min(24, parseInt(String(s.maxBookingHours || 4), 10)));
            bookingDayStart = String(s.bookingDayStart || '06:00');
            bookingDayEnd = String(s.bookingDayEnd || '23:00');
            rebuildBookableYmd();
            if (modalRoot) {
              populateDurationSelect();
            }
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
          if (modalRoot) {
            populateDurationSelect();
          }
        }

        applyVariationBookingRules(null);

        let modalRoot = null;
        let focusBefore = null;
        let ctx = {
          orderItemId: '',
          variation: null,
          selectedYmd: null,
          selectedDay: null,
          selectedStartIso: null,
          selectedBlock: null,
          calendars: null,
          /** @type {Array<{start: string, end: string, variationIds: number[]}>|null} */
          bufferSlotCandidates: null,
          visibleYm: '',
          playMinutes: durationGridMinutesCart,
        };

        function ensureModal() {
          if (modalRoot) {
            return modalRoot;
          }
          modalRoot = document.createElement('div');
          modalRoot.id = 'cb-cart-slot-modal';
          modalRoot.className = 'fixed inset-0 z-50 hidden';
          modalRoot.innerHTML = `
<div class="absolute inset-0 bg-slate-900/50" data-cb-cart-backdrop></div>
<div class="absolute inset-0 flex items-end justify-center p-4 sm:items-center" role="presentation">
  <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white shadow-xl" role="dialog" aria-modal="true" aria-labelledby="cb-cart-slot-title" data-cb-cart-dialog>
    <div class="border-b border-slate-100 px-5 py-4 flex items-start justify-between gap-3">
      <h2 id="cb-cart-slot-title" class="font-display text-lg font-semibold text-slate-900">${Drupal.checkPlain(Drupal.t('Change date and time'))}</h2>
      <button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-800" data-cb-cart-close aria-label="${Drupal.checkPlain(Drupal.t('Close'))}">✕</button>
    </div>
    <div class="px-5 py-4 space-y-4">
      <p class="text-center text-sm font-semibold uppercase tracking-wide text-slate-500" data-cb-cart-month></p>
      <div class="flex items-center justify-between gap-2">
        <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:border-[#02216E]" data-cb-strip-prev>${Drupal.checkPlain(Drupal.t('Prev'))}</button>
        <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:border-[#02216E]" data-cb-strip-next>${Drupal.checkPlain(Drupal.t('Next'))}</button>
      </div>
      <div class="flex gap-2 overflow-x-auto pb-1" role="list" data-cb-cart-dates></div>
      <div>
        <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="cb-cart-duration">${Drupal.checkPlain(Drupal.t('Duration'))}</label>
        <select id="cb-cart-duration" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" data-cb-cart-duration></select>
      </div>
      <div class="flex flex-wrap gap-2" role="list" data-cb-cart-times></div>
      <p class="text-sm text-amber-700 min-h-[1.25rem]" data-cb-cart-status></p>
      <div class="flex gap-3 pt-2">
        <button type="button" class="flex-1 rounded-xl border border-slate-200 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-cb-cart-cancel>${Drupal.checkPlain(Drupal.t('Cancel'))}</button>
        <button type="button" class="flex-1 rounded-xl bg-[#02216E] py-3 text-sm font-semibold text-white hover:bg-[#011550] disabled:opacity-50" data-cb-cart-save disabled>${Drupal.checkPlain(Drupal.t('Update'))}</button>
      </div>
    </div>
  </div>
</div>`;
          document.body.appendChild(modalRoot);

          modalRoot.querySelector('[data-cb-cart-backdrop]').addEventListener('click', closeModal);
          modalRoot.querySelector('[data-cb-cart-close]').addEventListener('click', closeModal);
          modalRoot.querySelector('[data-cb-cart-cancel]').addEventListener('click', closeModal);
          modalRoot.querySelector('[data-cb-cart-save]').addEventListener('click', onSave);
          modalRoot.querySelector('[data-cb-strip-prev]').addEventListener('click', () => shiftMonth(-1));
          modalRoot.querySelector('[data-cb-strip-next]').addEventListener('click', () => shiftMonth(1));
          modalRoot.querySelector('[data-cb-cart-duration]').addEventListener('change', () => {
            ctx.playMinutes = Math.max(1, parseInt(modalRoot.querySelector('[data-cb-cart-duration]').value, 10) || 60);
            ctx.selectedStartIso = null;
            ctx.selectedBlock = null;
            if (bufferMinutes > 0 && s.slotCandidatesUrl) {
              loadDayData().then(() => updateSaveState());
            } else {
              renderTimes();
              updateSaveState();
            }
          });

          document.addEventListener('keydown', onKeydown);
          return modalRoot;
        }

        function onKeydown(e) {
          if (!modalRoot || modalRoot.classList.contains('hidden')) {
            return;
          }
          if (e.key === 'Escape') {
            e.preventDefault();
            closeModal();
          }
        }

        function closeModal() {
          if (!modalRoot) {
            return;
          }
          modalRoot.classList.add('hidden');
          if (focusBefore) {
            focusBefore.focus();
            focusBefore = null;
          }
        }

        function openModal() {
          const m = ensureModal();
          m.classList.remove('hidden');
          focusBefore = document.activeElement;
          const dlg = m.querySelector('[data-cb-cart-dialog]');
          const fe = dlg.querySelector('button, [href], select, input, textarea, [tabindex]:not([tabindex="-1"])');
          if (fe) {
            fe.focus();
          }
        }

        function shiftMonth(delta) {
          if (!ctx.visibleYm) {
            return;
          }
          const [y, mo] = ctx.visibleYm.split('-').map((x) => parseInt(x, 10));
          const d = new Date(Date.UTC(y, mo - 1 + delta, 1));
          ctx.visibleYm = `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`;
          renderDateStrip();
        }

        function populateDurationSelect() {
          const el = modalRoot.querySelector('[data-cb-cart-duration]');
          el.innerHTML = '';
          const capMinutes = Math.max(1, Math.min(24, maxBookingHours)) * 60;
          for (let m = durationGridMinutesCart; m <= capMinutes; m += durationGridMinutesCart) {
            const opt = document.createElement('option');
            opt.value = String(m);
            opt.textContent = formatPlayDurationLabel(m);
            el.appendChild(opt);
          }
        }

        function daysInVisibleMonth() {
          return dates.filter((d) => d.ymd.slice(0, 7) === ctx.visibleYm);
        }

        function firstBookableDayInMonth(ym) {
          return dates.find((d) => d.ymd.slice(0, 7) === ym && isDaySelectableForCurrentVariation(d.ymd)) || null;
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

        function isDaySelectableForCurrentVariation(ymd) {
          if (!bookableYmd.has(ymd)) {
            return false;
          }
          if (!ctx.variation) {
            return true;
          }
          return closureForVariationOnYmd(ctx.variation.id, ymd) === null;
        }

        function renderDateStrip() {
          const elDates = modalRoot.querySelector('[data-cb-cart-dates]');
          const elMonth = modalRoot.querySelector('[data-cb-cart-month]');
          elDates.innerHTML = '';
          if (ctx.selectedYmd) {
            elMonth.textContent = formatMonthYearHeading(ctx.selectedYmd, siteTimeZoneId).toUpperCase();
          } else if (ctx.visibleYm) {
            elMonth.textContent = formatMonthYearHeading(`${ctx.visibleYm}-01`, siteTimeZoneId).toUpperCase();
          }
          if (!ctx.visibleYm && dates.length) {
            ctx.visibleYm = dates[0].ymd.slice(0, 7);
          }
          const slice = daysInVisibleMonth();
          slice.forEach((day) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.dataset.ymd = day.ymd;
            const disabled = !isDaySelectableForCurrentVariation(day.ymd);
            const on = day.ymd === ctx.selectedYmd;
            btn.className = disabled
              ? 'min-w-[4.5rem] shrink-0 cursor-not-allowed rounded-xl border border-slate-200 bg-slate-100 px-3 py-3 text-center text-slate-400 shadow-sm'
              : on
                ? 'min-w-[4.5rem] shrink-0 rounded-xl border-2 border-[#02216E] bg-[#02216E] px-3 py-3 text-center text-white shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-[#02216E]'
                : 'min-w-[4.5rem] shrink-0 rounded-xl border border-slate-200 bg-white px-3 py-3 text-center text-slate-800 shadow-sm hover:border-[#02216E] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#02216E]';
            const stripLabels = formatDateStripLabels(day.ymd, siteTimeZoneId);
            btn.innerHTML = `<span class="block text-xs font-medium uppercase ${on ? 'text-white' : 'text-slate-500'}">${Drupal.checkPlain(
              stripLabels.weekdayShort,
            )}</span><span class="mt-1 block font-display text-lg font-semibold">${Drupal.checkPlain(stripLabels.dayNumeric)}</span>`;
            if (disabled) {
              btn.disabled = true;
              btn.setAttribute('aria-disabled', 'true');
              const closure = ctx.variation ? closureForVariationOnYmd(ctx.variation.id, day.ymd) : null;
              btn.title = closure
                ? Drupal.t('This court is temporarily closed on this date.')
                : Drupal.t('Bookings are closed on this date.');
            }
            btn.addEventListener('click', () => selectDay(day));
            elDates.appendChild(btn);
          });
        }

        function selectDay(day) {
          if (!day) {
            return;
          }
          if (!isDaySelectableForCurrentVariation(day.ymd)) {
            ctx.selectedYmd = day.ymd;
            ctx.selectedDay = null;
            ctx.selectedStartIso = null;
            ctx.selectedBlock = null;
            ctx.calendars = null;
            ctx.bufferSlotCandidates = null;
            ctx.visibleYm = day.ymd.slice(0, 7);
            renderDateStrip();
            modalRoot.querySelector('[data-cb-cart-times]').innerHTML = '';
            const closure = ctx.variation ? closureForVariationOnYmd(ctx.variation.id, day.ymd) : null;
            modalRoot.querySelector('[data-cb-cart-status]').textContent = closure
              ? Drupal.t('This court is temporarily closed on this date.')
              : Drupal.t('Bookings are closed on this date.');
            updateSaveState();
            return;
          }
          ctx.selectedYmd = day.ymd;
          ctx.selectedDay = { ymd: day.ymd, from: day.from, to: day.to };
          ctx.visibleYm = day.ymd.slice(0, 7);
          ctx.selectedStartIso = null;
          ctx.selectedBlock = null;
          renderDateStrip();
          loadDayData();
          updateSaveState();
        }

        /**
         * @param {string} ymd
         * @param {number} variationId
         * @returns {Promise<Array<{start: string, end: string, variationIds: number[]}>>}
         */
        async function fetchBufferSlotCandidates(ymd, variationId) {
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
              duration_minutes: ctx.playMinutes,
              variation_ids: [variationId],
              quantity: 1,
            }),
          });
          if (!res.ok) {
            throw new Error(`Slot candidates HTTP ${res.status}`);
          }
          const data = await res.json();
          return Array.isArray(data.slots) ? data.slots : [];
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

        async function loadDayData() {
          const elStatus = modalRoot.querySelector('[data-cb-cart-status]');
          if (!ctx.variation || !ctx.selectedDay) {
            return;
          }
          const selectedClosure = closureForVariationOnYmd(ctx.variation.id, ctx.selectedDay.ymd);
          if (selectedClosure) {
            ctx.calendars = null;
            ctx.bufferSlotCandidates = [];
            modalRoot.querySelector('[data-cb-cart-times]').innerHTML = '';
            elStatus.textContent = selectedClosure.reason
              ? Drupal.t('This court is temporarily closed: @reason', { '@reason': selectedClosure.reason })
              : Drupal.t('This court is temporarily closed on this date.');
            return;
          }
          elStatus.textContent = Drupal.t('Loading availability…');
          modalRoot.querySelector('[data-cb-cart-times]').innerHTML = '';
          const useCandidates = bufferMinutes > 0 && s.slotCandidatesUrl;
          try {
            if (useCandidates) {
              ctx.calendars = null;
              ctx.bufferSlotCandidates = await fetchBufferSlotCandidates(ctx.selectedDay.ymd, ctx.variation.id);
            } else {
              ctx.bufferSlotCandidates = null;
              const cal = await fetchCalendar(ctx.variation.id, ctx.selectedDay.from, ctx.selectedDay.to);
              ctx.calendars = { [String(ctx.variation.id)]: cal };
            }
            renderTimes();
            elStatus.textContent = '';
          } catch (e) {
            elStatus.textContent = Drupal.t('Could not load availability. Please refresh.');
            if (useCandidates) {
              ctx.bufferSlotCandidates = null;
              ctx.calendars = null;
            } else {
              ctx.calendars = null;
              ctx.bufferSlotCandidates = null;
            }
            // eslint-disable-next-line no-console
            console.error(e);
          }
        }

        function renderTimes() {
          const elTimes = modalRoot.querySelector('[data-cb-cart-times]');
          elTimes.innerHTML = '';
          if (!ctx.selectedYmd || !ctx.variation) {
            return;
          }
          const tz = siteTimeZoneId;
          const v = ctx.variation;

          if (bufferMinutes > 0 && s.slotCandidatesUrl && ctx.bufferSlotCandidates !== null) {
            const nowMins = minutesNowInZoneForYmd(ctx.selectedYmd, tz);
            const sameDayClosed = sameDayCutoffMins !== null && nowMins >= 0 && nowMins > sameDayCutoffMins;
            const isToday = ctx.selectedYmd === todayYmd(tz);
            if (!ctx.bufferSlotCandidates.length) {
              const p = document.createElement('p');
              p.className = 'text-sm text-slate-500';
              p.textContent = Drupal.t('No time slots for this day.');
              elTimes.appendChild(p);
              return;
            }
            if (sameDayClosed) {
              const p = document.createElement('p');
              p.className = 'text-sm text-slate-500';
              p.textContent = Drupal.t('Same-day booking is closed after @time.', { '@time': sameDayCutoffHmDisplay });
              elTimes.appendChild(p);
              return;
            }
            let slots = ctx.bufferSlotCandidates
              .filter(
                (slot) =>
                  slot &&
                  slot.start &&
                  slot.end &&
                  Array.isArray(slot.variationIds) &&
                  slot.variationIds.some((id) => String(id) === String(v.id)),
              )
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
              return;
            }
            slots.forEach((slot) => {
              const btn = document.createElement('button');
              btn.type = 'button';
              btn.dataset.startIso = slot.start;

              const startM = minutesSinceMidnightInZone(slot.start, tz);
              const isPast = isToday && nowMins >= 0 && startM <= nowMins;

              setTimeSlotPillContent(btn, slot.start, slot.end, tz);

              const on = ctx.selectedStartIso === slot.start;

              btn.className = timeSlotPillClasses(on, isPast);

              if (isPast) {
                btn.disabled = true;
                btn.setAttribute('aria-disabled', 'true');
                elTimes.appendChild(btn);
                return;
              }

              btn.addEventListener('click', () => {
                ctx.selectedStartIso = slot.start;
                ctx.selectedBlock = { start: slot.start, end: slot.end };

                elTimes.querySelectorAll('button').forEach((b) => {
                  if (b.disabled) return;
                  const isOn = b.dataset.startIso === ctx.selectedStartIso;
                  b.className = timeSlotPillClasses(isOn, false);
                });

                updateSaveState();
              });

              elTimes.appendChild(btn);
            });
            return;
          }

          if (!ctx.calendars) {
            return;
          }
          const vid = String(ctx.variation.id);
          const cal = ctx.calendars[vid];
          const byStart = new Map();
          calendarEntries(cal).forEach((entry) => {
            if (!entry.start) {
              return;
            }
            if (ymdInZone(entry.start, tz) !== ctx.selectedYmd) {
              return;
            }
            if (!byStart.has(entry.start)) {
              byStart.set(entry.start, []);
            }
            byStart.get(entry.start).push({ entry });
          });
          const times = Array.from(byStart.keys()).sort();
          const openM = parseHmMinutes(bookingDayStart);
          const closeM = parseHmMinutes(bookingDayEnd);
          const hasWindow = openM !== null && closeM !== null && closeM > openM;
          const n = slotCountForVariation(v, slotMinutesDefault, ctx.playMinutes);
          const nowMins = minutesNowInZoneForYmd(ctx.selectedYmd, tz);
          const sameDayClosed = sameDayCutoffMins !== null && nowMins >= 0 && nowMins > sameDayCutoffMins;
          const isToday = ctx.selectedYmd === todayYmd(tz);
          const timesOk = times.filter((startIso) => {
            if (isToday && sameDayCutoffMins !== null) {
              const startMCut = minutesSinceMidnightInZone(startIso, tz);
              if (startMCut >= sameDayCutoffMins) {
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
            if (hasWindow) {
              const startM0 = minutesSinceMidnightInZone(startIso, tz);
              const endM0 = minutesSinceMidnightInZone(sample.end, tz);
              if (endM0 < startM0 || !(startM0 >= openM && endM0 <= closeM)) {
                return false;
              }
            }
            if (!n) {
              return false;
            }
            const requiredN =
              bufferMinutes > 0
                ? blockSlotCountForVariation(v, slotMinutesDefault, ctx.playMinutes, bufferMinutes)
                : n;
            if (!requiredN) {
              return false;
            }
            const slotLen = Math.max(1, Number(v.slotMinutes) || slotMinutesDefault);
            if (
              !matchesStaggeredStart(
                startIso,
                tz,
                openM,
                hasWindow,
                bufferMinutes,
                ctx.playMinutes,
                slotLen,
              )
            ) {
              return false;
            }
            const availabilityBlock = consecutiveBlock(cal, startIso, requiredN);
            if (!availabilityBlock) {
              return false;
            }
            const rentalEnd = playBufferEndIso(startIso, ctx.playMinutes, bufferMinutes);
            if (!rentalEnd || !slotFitsBookingWindow(startIso, rentalEnd, tz, hasWindow, openM, closeM)) {
              return false;
            }
            return true;
          });

          if (!times.length) {
            const p = document.createElement('p');
            p.className = 'text-sm text-slate-500';
            p.textContent = Drupal.t('No time slots for this day.');
            elTimes.appendChild(p);
            return;
          }
          if (sameDayClosed) {
            const p = document.createElement('p');
            p.className = 'text-sm text-slate-500';
            p.textContent = Drupal.t('Same-day booking is closed after @time.', { '@time': sameDayCutoffHmDisplay });
            elTimes.appendChild(p);
            return;
          }
          if (!timesOk.length) {
            const p = document.createElement('p');
            p.className = 'text-sm text-slate-500';
            p.textContent = Drupal.t('No slots for this duration within your booking hours.');
            elTimes.appendChild(p);
            return;
          }

          timesOk.forEach((startIso) => {
            const row = byStart.get(startIso) || [];
            const hasSelectable = row.some(({ entry }) => isEntrySelectable(entry));
            const rentalEnd = playBufferEndIso(startIso, ctx.playMinutes, bufferMinutes);

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.dataset.startIso = startIso;

            const startM = minutesSinceMidnightInZone(startIso, tz);
            const isPast = isToday && nowMins >= 0 && startM <= nowMins;

            setTimeSlotPillContent(btn, startIso, rentalEnd || '', tz);

            if (!hasSelectable || isPast) {
              btn.disabled = true;
              btn.className = timeSlotPillClasses(false, true);
              btn.setAttribute('aria-disabled', 'true');
              elTimes.appendChild(btn);
              return;
            }

            const on = ctx.selectedStartIso === startIso;
            btn.className = timeSlotPillClasses(on, false);

            btn.addEventListener('click', () => {
              ctx.selectedStartIso = startIso;

              const slotN = slotCountForVariation(v, slotMinutesDefault, ctx.playMinutes);
              const requiredN =
                bufferMinutes > 0
                  ? blockSlotCountForVariation(v, slotMinutesDefault, ctx.playMinutes, bufferMinutes)
                  : slotN;

              const gridOk = requiredN && consecutiveBlock(cal, startIso, requiredN);
              const rentEnd = playBufferEndIso(startIso, ctx.playMinutes, bufferMinutes);

              ctx.selectedBlock = gridOk && rentEnd ? { start: startIso, end: rentEnd } : null;

              elTimes.querySelectorAll('button').forEach((b) => {
                if (b.disabled) return;
                const isOn = b.dataset.startIso === ctx.selectedStartIso;
                b.className = timeSlotPillClasses(isOn, false);
              });

              updateSaveState();
            });

            elTimes.appendChild(btn);
          });
        }

        function updateSaveState() {
          const btn = modalRoot.querySelector('[data-cb-cart-save]');
          btn.disabled = !(ctx.selectedBlock && ctx.selectedBlock.start && ctx.selectedBlock.end);
        }

        async function onSave() {
          if (!ctx.selectedBlock || !ctx.orderItemId) {
            return;
          }
          const elStatus = modalRoot.querySelector('[data-cb-cart-status]');
          const btn = modalRoot.querySelector('[data-cb-cart-save]');
          elStatus.textContent = '';
          btn.disabled = true;
          try {
            const res = await fetch(Drupal.url(`court-booking/cart/slot/${ctx.orderItemId}`), {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': s.csrfToken,
              },
              body: JSON.stringify({
                start: ctx.selectedBlock.start,
                end: ctx.selectedBlock.end,
              }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
              elStatus.textContent = data.message || Drupal.t('Could not update booking.');
              btn.disabled = false;
              return;
            }
            window.location.reload();
          } catch (err) {
            elStatus.textContent = Drupal.t('Network error. Try again.');
            btn.disabled = false;
            // eslint-disable-next-line no-console
            console.error(err);
          }
        }

        document.body.addEventListener('click', (e) => {
          const t = e.target.closest('.cb-cart-slot-edit');
          if (!t) {
            return;
          }
          const orderItemId = t.getAttribute('data-cb-order-item-id');
          const variationId = t.getAttribute('data-cb-variation-id');
          if (!orderItemId || !variationId) {
            return;
          }
          const v = variationsById[String(variationId)] || variationsById[Number(variationId)];
          if (!v) {
            return;
          }
          applyVariationBookingRules(v);
          const capMinutes = Math.max(1, Math.min(24, maxBookingHours)) * 60;
          const snapPlayToGrid = (playMin) => {
            const grid = durationGridMinutesCart;
            if (playMin <= 0) {
              return grid;
            }
            let m = Math.round(playMin / grid) * grid;
            if (m < grid) {
              m = grid;
            }
            if (m > capMinutes) {
              m = Math.floor(capMinutes / grid) * grid;
            }
            if (m < grid) {
              m = grid;
            }
            return m;
          };
          ctx = {
            orderItemId,
            variation: v,
            selectedYmd: null,
            selectedDay: null,
            selectedStartIso: null,
            selectedBlock: null,
            calendars: null,
            bufferSlotCandidates: null,
            visibleYm: '',
            playMinutes: durationGridMinutesCart,
          };
          const startAttr = t.getAttribute('data-cb-slot-start');
          const endAttr = t.getAttribute('data-cb-slot-end');
          if (startAttr && endAttr) {
            const startMs = new Date(startAttr).getTime();
            const endMs = new Date(endAttr).getTime();
            if (endMs > startMs) {
              const totalMin = Math.round((endMs - startMs) / 60000);
              const playMin = Math.max(0, totalMin - bufferMinutes);
              ctx.playMinutes = snapPlayToGrid(playMin);
            }
            ctx.selectedYmd = ymdInZone(startAttr, siteTimeZoneId);
            if (isDaySelectableForCurrentVariation(ctx.selectedYmd)) {
              ctx.selectedDay = dates.find((d) => d.ymd === ctx.selectedYmd) || null;
            } else if (dates.length) {
              const first = firstBookableDayInMonth(ctx.visibleYm) || dates.find((d) => isDaySelectableForCurrentVariation(d.ymd)) || null;
              ctx.selectedYmd = first ? first.ymd : null;
              ctx.selectedDay = first;
            } else {
              ctx.selectedDay = null;
            }
            ctx.visibleYm = ctx.selectedYmd ? ctx.selectedYmd.slice(0, 7) : '';
          } else if (dates.length) {
            const first = dates.find((d) => isDaySelectableForCurrentVariation(d.ymd)) || null;
            ctx.selectedYmd = first ? first.ymd : null;
            ctx.selectedDay = first;
            ctx.visibleYm = ctx.selectedYmd ? ctx.selectedYmd.slice(0, 7) : '';
          }

          ensureModal();
          populateDurationSelect();
          renderDateStrip();
          if (ctx.selectedDay) {
            loadDayData().then(() => {
              if (startAttr && endAttr) {
                if (bufferMinutes > 0 && s.slotCandidatesUrl && ctx.bufferSlotCandidates) {
                  const match = ctx.bufferSlotCandidates.find((sl) => sl.start === startAttr);
                  if (
                    match &&
                    match.variationIds.some((id) => String(id) === String(v.id)) &&
                    new Date(match.end).getTime() === new Date(endAttr).getTime()
                  ) {
                    ctx.selectedStartIso = startAttr;
                    ctx.selectedBlock = { start: match.start, end: match.end };
                    renderTimes();
                  }
                } else if (ctx.calendars) {
                  const cal = ctx.calendars[String(v.id)];
                  const n = slotCountForVariation(v, slotMinutesDefault, ctx.playMinutes);
                  const requiredN =
                    bufferMinutes > 0
                      ? blockSlotCountForVariation(v, slotMinutesDefault, ctx.playMinutes, bufferMinutes)
                      : n;
                  if (
                    cal &&
                    requiredN &&
                    isEntrySelectable(calendarEntries(cal).find((e) => e.start === startAttr))
                  ) {
                    const block = consecutiveBlock(cal, startAttr, requiredN);
                    const rentEnd = playBufferEndIso(startAttr, ctx.playMinutes, bufferMinutes);
                    if (
                      block &&
                      rentEnd &&
                      new Date(rentEnd).getTime() === new Date(endAttr).getTime()
                    ) {
                      ctx.selectedStartIso = startAttr;
                      ctx.selectedBlock = { start: startAttr, end: rentEnd };
                      renderTimes();
                    }
                  }
                }
              }
              updateSaveState();
            });
          } else {
            modalRoot.querySelector('[data-cb-cart-status]').textContent = Drupal.t('No bookable dates in range.');
            updateSaveState();
          }
          openModal();
        });
      });
    },
  };
})(Drupal, drupalSettings, once);
