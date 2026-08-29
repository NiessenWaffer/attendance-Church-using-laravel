import { useState, useEffect, useRef, useCallback } from "react";

/**
 * OFFLINE-FIRST CONFIGURATION
 */
const db = new Dexie("AttendanceKioskDB");
db.version(1).stores({
  sync_queue: "++id, sync_id, member_id, session_id, created_at",
  members_cache: "member_code, id, first_name, last_name" // Local cache for offline lookup
});
db.version(2).stores({
  sync_queue: "++id, sync_id, member_id, session_id, created_at",
  members_cache: "member_code, id, first_name, last_name, profile_photo_url" // Local cache for offline lookup
});

const FETCH_TIMEOUT = 3000; // ms before failing over to offline mode

// ─── API ENDPOINTS ───────────────────────────────────────────────────────────
const API_MEMBER_LOOKUP = "/api/v1/attendance/check-in";
const API_MEMBER_CHECK_IN = "/api/v1/attendance/check-in";
const API_SYNC = "/api/attendance/sync";
const API_SESSIONS = "/api/attendance/sessions";
const API_RECENT = "/api/attendance/recent";
const API_TODAY_BY_SESSION = "/api/attendance/today/by-session";
const API_BIRTHDAYS = "/api/attendance/birthdays";
const API_ANNOUNCEMENT_VIDEO = "/api/system/settings/announcement-video";
// ─────────────────────────────────────────────────────────────────────────────

const KIOSK_API_KEY_STORAGE = "cas_kiosk_api_key";
const KIOSK_SESSION_LOCK_STORAGE = "cas_kiosk_session_lock";
const KIOSK_SETUP_MESSAGE = `This kiosk is not provisioned. Set the ${KIOSK_API_KEY_STORAGE} localStorage value on this device, then reload.`;
const SESSION_LOCK_REQUIRED_MESSAGE = "No session is locked. Ask an operator to select a live session and press Lock Session before scanning.";

function getKioskHeaders() {
  try {
    const key = window.localStorage.getItem(KIOSK_API_KEY_STORAGE)?.trim();
    if (!key) return null;
    return {
      "Content-Type": "application/json",
      "Authorization": `Bearer ${key}`,
    };
  } catch (_) {
    return null;
  }
}

const WELCOME_DURATION = 5000; // ms
const FONT_STACK = "var(--font-sans)";
const THEME = {
  page: "var(--color-page-bg)",
  surface: "var(--color-surface)",
  surfaceHover: "var(--color-surface-hover)",
  text: "var(--color-text)",
  textSecondary: "var(--color-text-secondary)",
  textMuted: "var(--color-text-muted)",
  border: "var(--color-border)",
  borderLight: "var(--color-border-light)",
};

/**
 * UUID Generator for offline records
 */
function generateUUID() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
    const r = Math.random() * 16 | 0, v = c === 'x' ? r : (r & 0x3 | 0x8);
    return v.toString(16);
  });
}

/**
 * Fetch with Timeout
 */
async function fetchWithTimeout(resource, options = {}) {
  const { timeout = FETCH_TIMEOUT } = options;
  const controller = new AbortController();
  const id = setTimeout(() => controller.abort(), timeout);
  try {
    return await fetch(resource, {
      ...options,
      signal: controller.signal
    });
  } finally {
    clearTimeout(id);
  }
}

function duplicateMetadata(data, fallbackName = "") {
  const details = data?.data || data?.errors || data || {};
  return {
    name: details.member_name || details.member?.name || fallbackName,
    time: details.previous_check_in_time || details.previous_time || details.attendance_time || "",
  };
}

function isDuplicateResponse(response, data) {
  const status = data?.status || data?.errors?.status || data?.data?.status;
  return response.status === 409 && status === "duplicate";
}

function useClock() {
  const [now, setNow] = useState(new Date());
  useEffect(() => {
    const t = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(t);
  }, []);
  return now;
}

function useViewport() {
  const getSize = () => ({
    width: typeof window === "undefined" ? 1200 : window.innerWidth,
    height: typeof window === "undefined" ? 800 : window.innerHeight,
  });
  const [size, setSize] = useState(getSize);

  useEffect(() => {
    const onResize = () => setSize(getSize());
    window.addEventListener("resize", onResize);
    return () => window.removeEventListener("resize", onResize);
  }, []);

  return size;
}

function formatDate(d) {
  return d.toLocaleDateString("en-US", {
    month: "long", day: "numeric", year: "numeric",
  });
}

function formatWeekday(d) {
  return d.toLocaleDateString("en-US", {
    weekday: "long",
  });
}

function formatTime(d) {
  return d.toLocaleTimeString("en-US", {
    hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: true,
  });
}

function todayInputDate() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

function timeToMinutes(value) {
  if (!value) return null;
  const match = String(value).match(/^(\d{1,2}):(\d{2})/);
  if (!match) return null;
  return Number(match[1]) * 60 + Number(match[2]);
}

function formatClockTime12h(value) {
  if (!value) return "";
  const match = String(value).trim().match(/^(\d{1,2}):(\d{2})(?::\d{2})?\s*(AM|PM)?$/i);
  if (!match) return String(value);

  let hour = Number(match[1]);
  const minute = match[2];
  const explicitPeriod = match[3]?.toUpperCase();
  if (explicitPeriod === "PM" && hour < 12) hour += 12;
  if (explicitPeriod === "AM" && hour === 12) hour = 0;

  const period = hour >= 12 ? "PM" : "AM";
  const hour12 = hour % 12 || 12;
  return `${hour12}:${minute} ${period}`;
}

function isLiveSession(session, now = new Date()) {
  if (!session || session.session_date !== todayInputDate()) return false;
  if (["completed", "cancelled"].includes(String(session.status || "").toLowerCase())) return false;
  const start = timeToMinutes(session.start_time) ?? 0;
  const end = timeToMinutes(session.end_time) ?? 1439;
  const current = now.getHours() * 60 + now.getMinutes();
  return current >= start && current <= end;
}

function isLiveSessionForServer(session, serverDate, serverTime) {
  if (!session || !serverDate || session.session_date !== serverDate) return false;
  if (["completed", "cancelled"].includes(String(session.status || "").toLowerCase())) return false;
  const start = timeToMinutes(session.start_time) ?? 0;
  const end = timeToMinutes(session.end_time) ?? 1439;
  const current = timeToMinutes(serverTime);
  if (current == null) return isLiveSession(session, new Date());
  return current >= start && current <= end;
}

function compareAutoSessions(a, b) {
  const typeRank = session => String(session?.service_type || "").toLowerCase() === "custom" ? 0 : 1;
  const scheduleRank = session => ["once", "one-time", "one_time"].includes(String(session?.schedule_type || "").toLowerCase()) ? 0 : 1;
  const start = session => timeToMinutes(session?.start_time) ?? 0;
  return typeRank(a) - typeRank(b)
    || scheduleRank(a) - scheduleRank(b)
    || start(b) - start(a)
    || Number(b?.id || 0) - Number(a?.id || 0);
}

function sessionTimeLabel(session) {
  const start = formatClockTime12h(session?.start_time);
  const end = formatClockTime12h(session?.end_time);
  if (start && end) return `${start} - ${end}`;
  if (start) return `Starts ${start}`;
  return "Live now";
}

function sessionName(session) {
  const title = String(session?.session_name || "").trim();
  const service = String(session?.service_time || "").trim();
  if (title && service && title.toLowerCase() !== service.toLowerCase()) return `${title} - ${service}`;
  return title || service || "Service";
}

function exactSessionLabel(session) {
  if (!session) return "";
  return `${sessionName(session)} | ${session.session_date} | ${sessionTimeLabel(session)} | Session #${session.id}`;
}

function readStoredSessionLock() {
  try {
    const stored = JSON.parse(window.localStorage.getItem(KIOSK_SESSION_LOCK_STORAGE) || "null");
    return stored?.id != null && stored?.session_date ? stored : null;
  } catch (_) {
    return null;
  }
}

function storeSessionLock(session) {
  try {
    if (!session) {
      window.localStorage.removeItem(KIOSK_SESSION_LOCK_STORAGE);
      return;
    }
    window.localStorage.setItem(KIOSK_SESSION_LOCK_STORAGE, JSON.stringify({
      id: session.id,
      session_date: session.session_date,
      service_time: session.service_time,
      session_name: session.session_name,
      start_time: session.start_time,
      end_time: session.end_time,
    }));
  } catch (_) {
    // The in-memory lock remains valid when storage is unavailable.
  }
}

function isCancelledSession(session) {
  return String(session?.status || "").toLowerCase() === "cancelled";
}

function hasSessionStartedForServer(session, serverDate, serverTime) {
  if (!session || !serverDate || session.session_date !== serverDate) return false;
  const start = timeToMinutes(session.start_time) ?? 0;
  const current = timeToMinutes(serverTime);
  if (current == null) {
    const now = new Date();
    return now.getHours() * 60 + now.getMinutes() >= start;
  }
  return current >= start;
}

function compareSessionsByStart(a, b) {
  const startA = timeToMinutes(a?.start_time) ?? 0;
  const startB = timeToMinutes(b?.start_time) ?? 0;
  return startA - startB || Number(a?.id || 0) - Number(b?.id || 0);
}

function summaryServiceLabel(session) {
  const name = String(session?.service_time || "Service").trim();
  const start = formatClockTime12h(session?.start_time);
  if (!start) return name;

  const normalizedName = name.toLowerCase();
  const normalizedStart = start.toLowerCase();
  if (normalizedName.includes(normalizedStart) || /\b\d{1,2}:\d{2}\s*(am|pm)?\b/i.test(name)) {
    return name;
  }

  return `${start} ${name}`;
}

function buildAttendanceSummaryRows(sessions, serverDate, serverTime) {
  const visibleSessions = (sessions || [])
    .filter(session => !isCancelledSession(session))
    .filter(session => {
      const count = Number(session.attendee_count || 0);
      return count > 0 || hasSessionStartedForServer(session, serverDate, serverTime);
    });
  const rankedLive = visibleSessions
    .filter(session => isLiveSessionForServer(session, serverDate, serverTime))
    .slice()
    .sort(compareAutoSessions);
  const activeLive = rankedLive[0] || null;
  const otherRows = visibleSessions
    .filter(session => !activeLive || String(session.id) !== String(activeLive.id))
    .slice()
    .sort(compareSessionsByStart);

  return activeLive ? [...otherRows, activeLive] : otherRows;
}

function memberDisplayName(member) {
  if (!member) return "";
  if (member.name) return member.name;
  return `${member.first_name || ""} ${member.last_name || ""}`.trim();
}

function memberCode(member) {
  return member?.member_code || member?.qr_code || member?.barcode || "";
}

function relativeTime(scan) {
  const ts = scan?.attended_at ? new Date(scan.attended_at).getTime() : scan?.ts;
  if (!ts) return scan?.time || "";
  const diffMs = Date.now() - ts;
  const diffSec = Math.max(0, Math.floor(diffMs / 1000));
  if (diffSec < 30) return "just now";
  if (diffSec < 60) return `${diffSec}s ago`;
  const diffMin = Math.floor(diffSec / 60);
  if (diffMin < 60) return `${diffMin} min ago`;
  return scan?.time || "";
}

// ─── COUNTER DIGIT COMPONENT (odometer-style slide animation) ───────────────
function CounterDigit({ digit }) {
  const [prevDigit, setPrevDigit] = useState(digit);
  const [isAnimating, setIsAnimating] = useState(false);

  useEffect(() => {
    if (digit !== prevDigit) {
      setIsAnimating(true);
      const timeout = setTimeout(() => {
        setPrevDigit(digit);
        setIsAnimating(false);
      }, 400); // Match CSS transition duration
      return () => clearTimeout(timeout);
    }
  }, [digit, prevDigit]);

  return (
    <div className="aside-counter-digit">
      <div className="aside-counter-digit-inner" style={{ transform: isAnimating ? 'translateY(-100%)' : 'translateY(0)', top: isAnimating ? '0' : '0' }}>
        {prevDigit}
      </div>
      {isAnimating && (
        <div className="aside-counter-digit-inner" style={{ transform: isAnimating ? 'translateY(0)' : 'translateY(100%)', top: isAnimating ? '0' : '0' }}>
          {digit}
        </div>
      )}
    </div>
  );
}

async function openQrCameraStream() {
  if (!navigator.mediaDevices?.getUserMedia) {
    throw new Error("Camera access is not available in this browser.");
  }

  try {
    return await navigator.mediaDevices.getUserMedia({
      video: {
        facingMode: { ideal: "environment" }
      }
    });
  } catch (error) {
    // Laptop webcams often do not expose an environment camera. Fall back to
    // the default camera so mobile emulation in DevTools can still test scans.
    if (error?.name === "OverconstrainedError" || error?.name === "NotFoundError") {
      return await navigator.mediaDevices.getUserMedia({ video: true });
    }
    throw error;
  }
}

async function canDecodeQrWithBrowser() {
  if (typeof window !== "undefined" && typeof window.jsQR === "function") {
    return true;
  }

  if (typeof window === "undefined" || !("BarcodeDetector" in window)) {
    return false;
  }

  const BarcodeDetectorApi = window.BarcodeDetector;
  if (typeof BarcodeDetectorApi.getSupportedFormats !== "function") {
    return true;
  }

  try {
    const formats = await BarcodeDetectorApi.getSupportedFormats();
    return formats.includes("qr_code");
  } catch (error) {
    return false;
  }
}

function isSecureCameraContext() {
  if (typeof window === "undefined") return false;
  const host = window.location.hostname;
  return window.isSecureContext || host === "localhost" || host === "127.0.0.1" || host === "::1";
}

// ─── YOUTUBE BACKGROUND PLAYER ───────────────────────────────────────────────
const DEFAULT_VIDEO_ID = "M7lc1UVf-VE";

function extractYoutubeId(input) {
  const s = String(input || "").trim();
  if (/^[A-Za-z0-9_-]{11}$/.test(s)) return s;
  const patterns = [
    /[?&]v=([A-Za-z0-9_-]{11})/,
    /youtu\.be\/([A-Za-z0-9_-]{11})/,
    /\/embed\/([A-Za-z0-9_-]{11})/,
    /\/shorts\/([A-Za-z0-9_-]{11})/,
  ];
  for (const re of patterns) {
    const match = s.match(re);
    if (match) return match[1];
  }
  return "";
}

function getInitialAnnouncementVideoId() {
  if (typeof window === "undefined") return DEFAULT_VIDEO_ID;
  const params = new URLSearchParams(window.location.search);
  return extractYoutubeId(params.get("video")) || extractYoutubeId(window.localStorage.getItem("woh_announcement_video_id")) || DEFAULT_VIDEO_ID;
}

function useAnnouncementVideoId() {
  const [videoId, setVideoId] = useState(getInitialAnnouncementVideoId);

  useEffect(() => {
    if (typeof window === "undefined") return;
    const params = new URLSearchParams(window.location.search);
    if (extractYoutubeId(params.get("video"))) return;

    let cancelled = false;
    fetch(API_ANNOUNCEMENT_VIDEO, { cache: "no-store" })
      .then(response => response.ok ? response.json() : null)
      .then(data => {
        if (cancelled || !data || data.status !== "success") return;
        const nextVideoId = extractYoutubeId(data.video_url) || extractYoutubeId(data.video_id) || extractYoutubeId(window.localStorage.getItem("woh_announcement_video_id")) || DEFAULT_VIDEO_ID;
        setVideoId(nextVideoId);
        window.localStorage.setItem("woh_announcement_video_id", nextVideoId);
      })
      .catch(() => {});

    return () => {
      cancelled = true;
    };
  }, []);

  return videoId;
}

function YouTubePlayer({ visible, cover = false }) {
  const videoId = useAnnouncementVideoId();
  const origin = typeof window === "undefined" ? "" : `&origin=${encodeURIComponent(window.location.origin)}`;
  const src = `https://www.youtube-nocookie.com/embed/${videoId}?autoplay=1&mute=1&loop=1&playlist=${videoId}&controls=0&playsinline=1&rel=0&modestbranding=1${origin}`;

  return (
    <div
      style={{
        position: "absolute", inset: 0,
        opacity: visible ? 1 : 0,
        transition: "opacity 0.6s ease",
        pointerEvents: "none",
      }}
    >
      <iframe
        src={src}
        allow="autoplay; encrypted-media; picture-in-picture; web-share"
        allowFullScreen
        referrerPolicy="strict-origin-when-cross-origin"
        style={cover ? {
          position: "absolute",
          top: "50%",
          left: "50%",
          width: "177.78vh",
          minWidth: "100%",
          height: "56.25vw",
          minHeight: "100%",
          border: "none",
          transform: "translate(-50%, -50%)",
        } : { width: "100%", height: "100%", border: "none" }}
        title="Announcement Video"
      />
    </div>
  );
}

// ─── WELCOME SCREEN — Clean professional check-in confirmation ──────────────
function WelcomeScreen({ member, visible, countdown, time, isOffline, isMobile }) {
  const totalDuration = Math.max(1, Math.round(WELCOME_DURATION / 1000));
  const progressPct = Math.max(0, Math.min(100, (countdown / totalDuration) * 100));
  const photoUrl = member?.profile_photo_url || member?.photo || member?.image_url || null;
  return (
    <div
      className="pa-feedback"
      style={{
        opacity: visible ? 1 : 0,
        transform: visible ? "translateY(0)" : "translateY(20px)",
        transition: "opacity 0.4s ease, transform 0.4s cubic-bezier(0.16, 1, 0.3, 1)",
        pointerEvents: visible ? "auto" : "none",
      }}
    >
      <div className="pa-feedback-icon pa-feedback-icon--success">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
          <polyline points="20 6 9 17 4 12" />
        </svg>
      </div>

      {photoUrl ? (
        <div className="pa-feedback-avatar">
          <img src={photoUrl} alt="" referrerPolicy="no-referrer" loading="lazy" onError={(e) => { e.currentTarget.style.display = "none"; }} />
        </div>
      ) : null}

      <p className="pa-feedback-eyebrow pa-feedback-eyebrow--success">Welcome Home</p>

      <h1 className="pa-feedback-name">{member?.name ?? "Member"}</h1>

      <div className="pa-feedback-badge pa-feedback-badge--success">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
          <polyline points="20 6 9 17 4 12" />
        </svg>
        Attendance Recorded{isOffline ? " · Offline" : ""}
      </div>

      {time && <p className="pa-feedback-meta">Checked in at {time}</p>}

      <div className="pa-feedback-progress">
        <div
          className="pa-feedback-progress-bar pa-feedback-progress-bar--success"
          style={{ width: `${progressPct}%` }}
        />
      </div>
    </div>
  );
}

function BirthdayScreen({ celebration, visible, countdown }) {
  const confetti = Array.from({ length: 26 }, (_, index) => index);
  const totalDuration = Math.max(1, Math.round(WELCOME_DURATION / 1000));
  const progressPct = Math.max(0, Math.min(100, (countdown / totalDuration) * 100));
  const photoUrl = celebration?.photo || null;

  return (
    <div className="pa-birthday" style={{ opacity: visible ? 1 : 0, pointerEvents: visible ? "auto" : "none" }}>
      <div className="pa-birthday-confetti" aria-hidden="true">
        {confetti.map((piece) => (
          <span
            key={piece}
            style={{ left: `${(piece * 37) % 100}%`, animationDelay: `${piece * -0.16}s`, animationDuration: `${2.8 + (piece % 5) * 0.35}s` }}
          />
        ))}
      </div>
      <div className="pa-birthday-glow" aria-hidden="true" />
      <div className="pa-birthday-card">
        <div className="pa-birthday-crown" aria-hidden="true">✦</div>
        <p className="pa-birthday-eyebrow">This month we celebrate you</p>
        {photoUrl ? (
          <div className="pa-birthday-photo">
            <img src={photoUrl} alt="" referrerPolicy="no-referrer" loading="lazy" onError={(e) => { e.currentTarget.style.display = "none"; }} />
          </div>
        ) : (
          <div className="pa-birthday-cake" aria-hidden="true">🎂</div>
        )}
        <h1 className="pa-birthday-name">Happy Birthday, {celebration?.name || "Friend"}!</h1>
        {celebration?.age ? <p className="pa-birthday-age">Celebrating {celebration.age} wonderful years</p> : null}
        <p className="pa-birthday-message">{celebration?.message || "Wishing you a beautiful year ahead."}</p>
        <div className="pa-birthday-divider"><span>♥</span></div>
        <p className="pa-birthday-signoff">From your Word of Hope family</p>
        <div className="pa-feedback-progress pa-birthday-progress">
          <div className="pa-feedback-progress-bar" style={{ width: `${progressPct}%` }} />
        </div>
      </div>
    </div>
  );
}

// ─── ERROR SCREEN — Clean professional error display ─────────────────────────
function ErrorScreen({ message, visible, countdown }) {
  // Map specific errors to user-friendly title + helper pairs
  const getDisplay = (msg) => {
    if (!msg) return { title: "An error occurred", helper: "Please try again or ask a host for help." };
    const lower = msg.toLowerCase();
    if (lower.includes("no active schedule") || lower.includes("no active service")) {
      return {
        title: "No Active Schedule",
        helper: "There is no service open for check-in at this time.",
      };
    }
    if (lower.includes("member not found")) {
      return {
        title: "Member Not Found",
        helper: "We couldn't find this member. Please try again or ask a host.",
      };
    }
    if (lower.includes("already") || lower.includes("duplicate")) {
      return {
        title: "Already Checked In",
        helper: "Your attendance was already recorded for this service.",
      };
    }
    if (lower.includes("attendance for") && lower.includes("only available")) {
      return { title: "Outside Check-In Window", helper: msg };
    }
    return { title: "Unable to Check In", helper: msg };
  };

  const { title, helper } = getDisplay(message);
  const totalDuration = Math.max(1, Math.round(WELCOME_DURATION / 1000));
  const progressPct = Math.max(0, Math.min(100, (countdown / totalDuration) * 100));

  return (
    <div
      className="pa-feedback"
      style={{
        opacity: visible ? 1 : 0,
        transform: visible ? "scale(1)" : "scale(1.02)",
        transition: "opacity 0.4s ease, transform 0.4s ease",
        pointerEvents: visible ? "auto" : "none",
      }}
    >
      <div className="pa-feedback-icon pa-feedback-icon--error">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
          <circle cx="12" cy="12" r="10" />
          <line x1="12" y1="8" x2="12" y2="13" />
          <line x1="12" y1="16" x2="12.01" y2="16" />
        </svg>
      </div>

      <p className="pa-feedback-eyebrow pa-feedback-eyebrow--error">Unable to Check In</p>

      <h1 className="pa-feedback-message">{title}</h1>

      {helper && <p className="pa-feedback-helper">{helper}</p>}

      <div className="pa-feedback-progress">
        <div
          className="pa-feedback-progress-bar pa-feedback-progress-bar--error"
          style={{ width: `${progressPct}%` }}
        />
      </div>
    </div>
  );
}

// ─── DUPLICATE SCREEN — Already checked in, friendly reminder ───────────────
function DuplicateScreen({ memberName, previousTime, visible, countdown }) {
  const totalDuration = Math.max(1, Math.round(WELCOME_DURATION / 1000));
  const progressPct = Math.max(0, Math.min(100, (countdown / totalDuration) * 100));

  return (
    <div
      className="pa-feedback"
      style={{
        opacity: visible ? 1 : 0,
        transform: visible ? "scale(1)" : "scale(1.02)",
        transition: "opacity 0.4s ease, transform 0.4s ease",
        pointerEvents: visible ? "auto" : "none",
      }}
    >
      <div className="pa-feedback-icon pa-feedback-icon--warn">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
          <circle cx="12" cy="12" r="10" />
          <polyline points="12 6 12 12 16 14" />
        </svg>
      </div>

      <p className="pa-feedback-eyebrow pa-feedback-eyebrow--warn">Already Checked In</p>

      <h1 className="pa-feedback-name">{memberName || ""}</h1>

      <div className="pa-feedback-badge pa-feedback-badge--warn">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
          <circle cx="12" cy="12" r="10" />
          <polyline points="12 6 12 12 16 14" />
        </svg>
        {previousTime
          ? `First check-in at ${previousTime}`
          : "Your attendance was already recorded"}
      </div>

      <p className="pa-feedback-meta">Thank you for being faithful!</p>

      <div className="pa-feedback-progress">
        <div
          className="pa-feedback-progress-bar pa-feedback-progress-bar--warn"
          style={{ width: `${progressPct}%` }}
        />
      </div>
    </div>
  );
}

// ─── CONFIRM SCREEN (Mobile Bottom Sheet) ───────────────────────────────────
function ConfirmScreen({ member, onConfirm, onCancel, visible }) {
  if (!visible) return null;
  return (
    <div className="pa-confirm-backdrop" onClick={onCancel}>
      <div className="pa-confirm-sheet" onClick={e => e.stopPropagation()}>
        <div className="pa-confirm-handle" />
        <div className="pa-confirm-avatar">
          {(member?.first_name || "?")[0]}{(member?.last_name || "")[0]}
          {member?.profile_photo_url ? (
            <img
              src={member.profile_photo_url}
              alt=""
              className="pa-avatar__img"
              referrerPolicy="no-referrer"
              loading="lazy"
              onError={(e) => { e.currentTarget.style.display = "none"; }}
            />
          ) : null}
        </div>
        <h2 className="pa-confirm-name">{member ? memberDisplayName(member) : "Member Name"}</h2>
        <p className="pa-confirm-code">{memberCode(member)}</p>
        <div className="pa-confirm-warning">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
            <line x1="12" y1="9" x2="12" y2="13" />
            <line x1="12" y1="17" x2="12.01" y2="17" />
          </svg>
          This will record attendance for this person
        </div>
        <div className="pa-confirm-buttons">
          <button
            onClick={onCancel}
            className="pa-confirm-btn pa-confirm-btn--cancel"
          >
            Cancel
          </button>
          <button
            onClick={onConfirm}
            className="pa-confirm-btn pa-confirm-btn--confirm"
          >
            Confirm Check-In
          </button>
        </div>
      </div>
    </div>
  );
}

// ─── SEARCH RESULTS (Mobile Only) ──────────────────────────────────────────
function SearchResults({ results, onSelect, visible }) {
  if (!visible || results.length === 0) return null;
  return (
    <div style={{
      position: "absolute", top: "100%", left: 0, right: 0,
      background: "#ffffff", border: "1px solid #e5e7eb",
      borderRadius: 0, boxShadow: "0 4px 12px rgba(0,0,0,0.08)",
      zIndex: 1000, maxHeight: "45vh", overflowY: "auto", marginTop: -1
    }}>
      {results.map(m => (
        <div
          key={m.id}
          onClick={() => onSelect(m)}
          style={{
            padding: "16px", borderBottom: "1px solid #f3f4f6",
            display: "flex", alignItems: "center", gap: 12, cursor: "pointer"
          }}
        >
          <div style={{
            width: 32, height: 32, borderRadius: "0", background: "#f3f4f6",
            display: "flex", alignItems: "center", justifyContent: "center", fontSize: 12, fontWeight: 700, color: "#1f2937",
            position: "relative", overflow: "hidden"
          }}>
            {(m.first_name || "?")[0]}{(m.last_name || "")[0]}
            {m.profile_photo_url ? (
              <img
                src={m.profile_photo_url}
                alt=""
                style={{ position: "absolute", inset: 0, width: "100%", height: "100%", objectFit: "cover" }}
                referrerPolicy="no-referrer"
                loading="lazy"
                onError={(e) => { e.currentTarget.style.display = "none"; }}
              />
            ) : null}
          </div>
          <div>
            <div style={{ fontWeight: 600, color: "#1f2937" }}>{memberDisplayName(m)}</div>
            <div style={{ fontSize: 11, color: "#6b7280" }}>{memberCode(m)}</div>
          </div>
        </div>
      ))}
    </div>
  );
}

// ─── MOBILE IDLE SCREEN — Membership Dashboard ───────────────────────────────
const MOBILE_AVATAR_COLORS = [
  "#1e40af", "#16a34a", "#dc2626", "#b45309", "#0d9488",
  "#7c3aed", "#db2777", "#0891b2", "#65a30d", "#ea580c"
];

function avatarColorForName(name) {
  const str = String(name || "");
  let hash = 0;
  for (let i = 0; i < str.length; i++) {
    hash = (hash * 31 + str.charCodeAt(i)) >>> 0;
  }
  return MOBILE_AVATAR_COLORS[hash % MOBILE_AVATAR_COLORS.length];
}

function initialFor(name) {
  const trimmed = String(name || "").trim();
  return trimmed ? trimmed.charAt(0).toUpperCase() : "?";
}

function sortMobileSessions(sessions, liveSessionId) {
  return [...(sessions || [])].sort((a, b) => {
    const aLive = liveSessionId && String(a.id) === String(liveSessionId);
    const bLive = liveSessionId && String(b.id) === String(liveSessionId);
    if (aLive && !bLive) return -1;
    if (!aLive && bLive) return 1;
    const startA = timeToMinutes(a.start_time) ?? 0;
    const startB = timeToMinutes(b.start_time) ?? 0;
    return startA - startB || Number(a.id) - Number(b.id);
  });
}

function MobileIdleScreen({ visible, total, sessions, liveSessionId, loading }) {
  const sortedSessions = sortMobileSessions(sessions, liveSessionId);
  const defaultExpanded = liveSessionId
    || (sortedSessions.find(s => (s.members || []).length > 0)?.id)
    || (sortedSessions[0]?.id)
    || "";
  const [expandedId, setExpandedId] = useState(String(defaultExpanded || ""));

  useEffect(() => {
    if (!defaultExpanded) return;
    setExpandedId(prev => {
      if (prev && sortedSessions.some(s => String(s.id) === String(prev))) return prev;
      return String(defaultExpanded);
    });
  }, [defaultExpanded, sortedSessions.map(s => s.id).join(",")]);

  if (!visible) return null;

  return (
    <div className="mobile-idle-hero">
      <div className="mobile-overview-card">
        <h2 className="mobile-overview-title">TOTAL UNIQUE ATTENDANCE</h2>
        <div className="mobile-overview-row">
          <span className="mobile-overview-total">{Number(total || 0)}</span>
        </div>
      </div>

      <div className="mobile-session-groups">
        {loading && sortedSessions.length === 0 ? (
          <div className="mobile-recent-empty">Loading attendance…</div>
        ) : sortedSessions.length === 0 ? (
          <div className="mobile-recent-empty">No sessions scheduled for today.</div>
        ) : (
          sortedSessions.map((session) => {
            const members = Array.isArray(session.members) ? session.members : [];
            const isExpanded = String(session.id) === String(expandedId);
            const isLive = Boolean(session.is_live) || (liveSessionId && String(session.id) === String(liveSessionId));
            return (
              <div key={session.id} className={`mobile-session-group${isLive ? " mobile-session-group--live" : ""}`}>
                <button
                  type="button"
                  className="mobile-session-group-toggle"
                  onClick={() => setExpandedId(prev => (String(prev) === String(session.id) ? "" : String(session.id)))}
                >
                  <span className="mobile-session-group-caret">{isExpanded ? "▼" : "▶"}</span>
                  <span className="mobile-session-group-title">{session.service_time}</span>
                  {isLive && <span className="mobile-session-group-live">LIVE</span>}
                  <span className="mobile-session-group-count">{members.length}</span>
                </button>
                {isExpanded && (
                  <div className="mobile-session-group-body">
                    {members.length === 0 ? (
                      <div className="mobile-recent-empty">No one has checked in yet.</div>
                    ) : (
                      members.map((member, idx) => (
                        <div className="mobile-recent-row" key={`${session.id}-${member.name}-${idx}`}>
                          <div className="mobile-recent-avatar" aria-hidden="true">
                            {initialFor(member.name)}
                            {member.profile_photo_url ? (
                              <img
                                src={member.profile_photo_url}
                                alt=""
                                className="pa-avatar__img"
                                referrerPolicy="no-referrer"
                                loading="lazy"
                                onError={(e) => { e.currentTarget.style.display = "none"; }}
                              />
                            ) : null}
                          </div>
                          <div className="mobile-recent-text">
                            <span className="mobile-recent-name">{member.name}</span>
                          </div>
                        </div>
                      ))
                    )}
                  </div>
                )}
              </div>
            );
          })
        )}
      </div>
    </div>
  );
}

// ─── CONNECTION BADGE ────────────────────────────────────────────────────────
function ConnectionBadge({ isOnline }) {
  if (isOnline) return null;

  const badgeStyle = {
    display: "flex",
    alignItems: "center",
    gap: "8px",
    padding: "6px 12px",
    borderRadius: "0",
    fontSize: "11px",
    fontWeight: "600",
    transition: "all 0.3s ease",
    boxShadow: "0 2px 4px rgba(0,0,0,0.05)",
    border: "1px solid",
    backgroundColor: "#fff7ed",
    color: "#d97706",
    borderColor: "#fbbf24",
    flexShrink: 0
  };

  const dotStyle = {
    width: "6px",
    height: "6px",
    borderRadius: "0",
    backgroundColor: "currentColor"
  };

  return (
    <div style={badgeStyle}>
      <div style={dotStyle}></div>
      <span>
        Offline Mode: Saving Locally
      </span>
      {window.innerWidth < 768 && (
        <span style={{ fontSize: "9px", opacity: 0.8, marginLeft: "4px" }}>
          (IndexedDB Active)
        </span>
      )}
    </div>
  );
}

function AttendanceSummaryTable({ rows, total, loading, error }) {
  return (
    <div className="aside-attendance-summary" aria-live="polite">
      <div className="aside-summary-title">Attendance Today</div>
      <table className="aside-summary-table">
        <tbody>
          {loading && rows.length === 0 ? (
            <tr className="aside-summary-muted-row">
              <td className="aside-summary-service">Loading...</td>
              <td className="aside-summary-count">...</td>
            </tr>
          ) : error && rows.length === 0 ? (
            <tr className="aside-summary-muted-row">
              <td className="aside-summary-service">Summary unavailable</td>
              <td className="aside-summary-count">0</td>
            </tr>
          ) : rows.length === 0 ? (
            <tr className="aside-summary-muted-row">
              <td className="aside-summary-service">No services yet</td>
              <td className="aside-summary-count">0</td>
            </tr>
          ) : rows.map(session => (
            <tr key={session.id}>
              <td className="aside-summary-service" title={summaryServiceLabel(session)}>
                {summaryServiceLabel(session)}
              </td>
              <td className="aside-summary-count">{Number(session.attendee_count || 0)}</td>
            </tr>
          ))}
          <tr className="aside-summary-total-row">
            <td className="aside-summary-service">Total Members Today</td>
            <td className="aside-summary-count">{Number(total || 0)}</td>
          </tr>
        </tbody>
      </table>
    </div>
  );
}

function SessionLockPanel({ sessions, lockedSession, recommendedSessionId, selectedId, onSelect, onLock, onUnlock, loading }) {
  const hasSessions = sessions.length > 0;
  const selectedIsLocked = lockedSession && String(selectedId) === String(lockedSession.id);

  return (
    <div style={{
      display: "flex", flexDirection: "column", gap: 8, flexShrink: 0,
      padding: "10px 12px", background: lockedSession ? "#eff6ff" : "#fff7ed",
      borderBottom: `1px solid ${lockedSession ? "#93c5fd" : "#fdba74"}`,
      fontFamily: FONT_STACK,
    }}>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 12, flexWrap: "wrap" }}>
        <div style={{ minWidth: 0, flex: "1 1 260px" }}>
          <div style={{ fontSize: 10, fontWeight: 800, letterSpacing: "0.1em", color: lockedSession ? "#1d4ed8" : "#c2410c" }}>
            {lockedSession ? "SESSION LOCKED" : "OPERATOR SESSION LOCK REQUIRED"}
          </div>
          <div style={{ marginTop: 2, fontSize: 13, fontWeight: 700, color: "#0f172a", overflow: "hidden", textOverflow: "ellipsis" }}>
            {lockedSession
              ? exactSessionLabel(lockedSession)
              : loading
                ? "Loading today's live sessions..."
                : hasSessions
                  ? "Select the exact session below, then press Lock Session."
                  : "No live session is available for check-in right now."}
          </div>
        </div>
        <div style={{ display: "flex", alignItems: "center", gap: 6, flex: "1 1 360px", justifyContent: "flex-end", flexWrap: "wrap" }}>
          <select
            aria-label="Live session to lock"
            value={selectedId}
            onChange={event => onSelect(event.target.value)}
            disabled={!hasSessions}
            style={{
              flex: "1 1 220px", maxWidth: 420, minWidth: 0, height: 34, padding: "0 8px",
              border: "1px solid #94a3b8", borderRadius: 4, background: "#ffffff", color: "#0f172a",
              fontSize: 12, fontWeight: 600,
            }}
          >
            {!hasSessions && <option value="">No live sessions</option>}
            {sessions.map(session => (
              <option key={session.id} value={String(session.id)}>
                {exactSessionLabel(session)}{String(session.id) === String(recommendedSessionId) ? " (Recommended)" : ""}
              </option>
            ))}
          </select>
          <button
            type="button"
            onClick={onLock}
            disabled={!hasSessions || !selectedId || selectedIsLocked}
            style={{
              height: 34, padding: "0 12px", border: 0, borderRadius: 4,
              background: selectedIsLocked ? "#94a3b8" : "#1d4ed8", color: "#ffffff",
              fontSize: 11, fontWeight: 800, letterSpacing: "0.04em", cursor: selectedIsLocked ? "default" : "pointer",
            }}
          >
            {selectedIsLocked ? "LOCKED" : lockedSession ? "CHANGE LOCK" : "LOCK SESSION"}
          </button>
          {lockedSession && (
            <button
              type="button"
              onClick={onUnlock}
              style={{
                height: 34, padding: "0 10px", border: "1px solid #94a3b8", borderRadius: 4,
                background: "#ffffff", color: "#475569", fontSize: 11, fontWeight: 700, cursor: "pointer",
              }}
            >
              UNLOCK
            </button>
          )}
        </div>
      </div>
      {!lockedSession && hasSessions && recommendedSessionId && (
        <div style={{ fontSize: 10, color: "#9a3412" }}>
          The recommended session is preselected for review only. Scans remain blocked until an operator locks it.
        </div>
      )}
    </div>
  );
}

// ─── MAIN COMPONENT ──────────────────────────────────────────────────────────
export default function PublicAttendance() {

  const now = useClock();
  const viewport = useViewport();
  const isMobile = viewport.width < 768;
  const isSmall = viewport.width < 480;
  const searchRef = useRef(null);
  const timerRef = useRef(null);
  const countdownRef = useRef(null);
  const lastScanIdRef = useRef(0);
  const birthdayAudioRef = useRef(null);
  const qrVideoRef = useRef(null);
  const qrStreamRef = useRef(null);
  const qrScanFrameRef = useRef(null);
  const qrDetectorRef = useRef(null);
  const qrCanvasRef = useRef(null);
  const memberSearchRequestRef = useRef(0);
  const counterRef = useRef(null);
  const syncInFlightRef = useRef(false);
  const lockedSessionRef = useRef(null);

  const [scanBuffer, setScanBuffer] = useState("");
  const [state, setState] = useState("idle"); // "idle" | "welcome" | "error" | "duplicate" | "loading" | "confirming"
  const [member, setMember] = useState(null);
  const [birthday, setBirthday] = useState(null);
  const [soundMuted, setSoundMuted] = useState(false);
  const [lastTime, setLastTime] = useState(null);
  const [recentScans, setRecentScans] = useState([]);
  const [monthCelebrants, setMonthCelebrants] = useState([]);
  const [celebrantMonth, setCelebrantMonth] = useState("");
  const [countdown, setCountdown] = useState(5);
  const [errorMsg, setErrorMsg] = useState("");
  const [duplicateName, setDuplicateName] = useState("");
  const [duplicateTime, setDuplicateTime] = useState("");
  const [verse, setVerse] = useState(null);
  
  // Member Search State
  const [searchResults, setSearchResults] = useState([]);
  const [isSearching, setIsSearching] = useState(false);
  const [selectedMemberForConfirm, setSelectedMemberForConfirm] = useState(null);

  // Offline Sync State
  const [isOnline, setIsOnline] = useState(navigator.onLine);
  const [isSyncing, setIsSyncing] = useState(false);
  const [pendingCount, setPendingCount] = useState(0);
  const [syncError, setSyncError] = useState("");
  const [credentialError, setCredentialError] = useState(() => getKioskHeaders() ? "" : KIOSK_SETUP_MESSAGE);
  const [lockedSession, setLockedSession] = useState(null);
  const [liveSessions, setLiveSessions] = useState([]);
  const [recommendedSessionId, setRecommendedSessionId] = useState("");
  const [selectedSessionId, setSelectedSessionId] = useState("");
  const [sessionsValidated, setSessionsValidated] = useState(false);
  const [attendanceSummaryRows, setAttendanceSummaryRows] = useState([]);
  const [attendanceSummaryTotal, setAttendanceSummaryTotal] = useState(0);
  const [attendanceSummaryLoading, setAttendanceSummaryLoading] = useState(true);
  const [attendanceSummaryError, setAttendanceSummaryError] = useState("");
  const [todaySessionRosters, setTodaySessionRosters] = useState([]);
  const [todayRostersLoading, setTodayRostersLoading] = useState(true);
  const [isOfflineRecord, setIsOfflineRecord] = useState(false);
  const [logoFailed, setLogoFailed] = useState(false);
  const [mobileActionsVisible, setMobileActionsVisible] = useState(false);
  const [isQrSupported, setIsQrSupported] = useState(false);
  const [isQrScanning, setIsQrScanning] = useState(false);
  const [qrScannerMessage, setQrScannerMessage] = useState("Point the camera at the QR code");

  const autoSubmitTimerRef = useRef(null);
  const mobileActionsTimerRef = useRef(null);

  // Public Attendance Enabled State
  const [isEnabled, setIsEnabled] = useState(true);
  const [isCheckingEnabled, setIsCheckingEnabled] = useState(true);
  const publicEnabledRef = useRef(true);

  // Member Search Logic for Mobile
  // Disabled: this kiosk only checks in members by scanning codes (no local
  // member store to search). Results are kept empty.
  useEffect(() => {
    if (!isMobile || !isEnabled) return;
    memberSearchRequestRef.current += 1;
    setSearchResults([]);
    setIsSearching(false);
  }, [scanBuffer, isMobile, isEnabled]);

  const checkEnabledStatus = useCallback(async () => {
    try {
      const res = await fetch('/api/system/settings/public-attendance', { cache: 'no-store' });
      const data = await res.json();
      const nextEnabled = Boolean(data.enabled);
      if (publicEnabledRef.current === false && nextEnabled === true) {
        window.location.reload();
        return;
      }
      publicEnabledRef.current = nextEnabled;
      setIsEnabled(nextEnabled);
      setSoundMuted(data.sound_enabled === false);
      if (data.theme && window.ThemeManager && typeof window.ThemeManager.applyTheme === 'function') {
        window.ThemeManager.applyTheme(data.theme);
      }
    } catch (error) {
      console.error("[SYSTEM] Failed to check enabled status:", error);
      setIsEnabled(true);
    } finally {
      setIsCheckingEnabled(false);
    }
  }, []);

  const fetchRandomVerse = useCallback(async (force = false) => {
    const VERSE_CACHE_KEY = 'pa_random_verse';
    const VERSE_TTL_MS = 600000; // 10 minutes

    if (!force) {
      try {
        const cached = localStorage.getItem(VERSE_CACHE_KEY);
        if (cached) {
          const { text, ref, ts } = JSON.parse(cached);
          if (text && ref && ts && (Date.now() - ts) < VERSE_TTL_MS) {
            setVerse({ text, ref });
            return;
          }
        }
      } catch (_) { /* ignore cache errors */ }
    }

    try {
      const res = await fetch('https://labs.bible.org/api/?passage=random&type=json', { cache: 'no-store' });
      if (!res.ok) return;
      const data = await res.json();
      if (Array.isArray(data) && data.length > 0) {
        const v = data[0];
        const cleanText = String(v.text || '').replace(/<[^>]*>/g, '').trim();
        if (cleanText) {
          const next = { text: cleanText, ref: `${v.bookname} ${v.chapter}:${v.verse}` };
          setVerse(next);
          try {
            localStorage.setItem(VERSE_CACHE_KEY, JSON.stringify({ ...next, ts: Date.now() }));
          } catch (_) { /* ignore quota errors */ }
        }
      }
    } catch (err) {
      console.warn('[VERSE] Failed to load random verse:', err.message);
    }
  }, []);

  const fetchRecentScans = useCallback(async () => {
    try {
      const headers = getKioskHeaders();
      if (!headers) return;
      const res = await fetch(API_RECENT, { cache: 'no-store', headers });
      if (!res.ok) return;
      const data = await res.json();
      if (data.success && Array.isArray(data.recent)) {
        setRecentScans(data.recent);
      }
    } catch (err) {
      console.warn('[RECENT] Failed to load recent scans:', err.message);
    }
  }, []);

  const fetchMonthCelebrants = useCallback(async () => {
    try {
      const headers = getKioskHeaders();
      if (!headers) return;
      const res = await fetch(API_BIRTHDAYS, { cache: 'no-store', headers });
      if (!res.ok) return;
      const data = await res.json();
      if (data.success && Array.isArray(data.birthdays)) {
        setMonthCelebrants(data.birthdays);
        if (data.month_label) setCelebrantMonth(data.month_label);
      }
    } catch (err) {
      console.warn('[BIRTHDAYS] Failed to load month celebrants:', err.message);
    }
  }, []);

  const fetchTodaySessionRosters = useCallback(async () => {
    try {
      const headers = getKioskHeaders();
      if (!headers) return;
      const res = await fetch(API_TODAY_BY_SESSION, { cache: 'no-store', headers });
      if (!res.ok) return;
      const data = await res.json();
      if (data.success && Array.isArray(data.sessions)) {
        setTodaySessionRosters(data.sessions);
        if (typeof data.total_unique === 'number') {
          setAttendanceSummaryTotal(Number(data.total_unique));
        }
      }
    } catch (err) {
      console.warn('[TODAY ROSTERS] Failed to load session rosters:', err.message);
    } finally {
      setTodayRostersLoading(false);
    }
  }, []);

  const refreshAttendanceSummary = useCallback(async () => {
    const fetchSessionsForDate = async (dateValue) => {
      const headers = getKioskHeaders();
      if (!headers) return;
      const res = await fetch(`${API_SESSIONS}?date=${encodeURIComponent(dateValue)}`, { headers });
      if (!res.ok) throw new Error("Failed to load sessions");
      return res.json();
    };

    setAttendanceSummaryLoading(true);
    try {
      const clientDate = todayInputDate();
      let data = await fetchSessionsForDate(clientDate);
      let serverDate = data.server_date || clientDate;

      if (serverDate !== clientDate) {
        data = await fetchSessionsForDate(serverDate);
        serverDate = data.server_date || serverDate;
      }

      const sessions = Array.isArray(data.sessions) ? data.sessions : [];
      const serverTime = data.server_time || "";
      const availableLiveSessions = sessions
        .filter(session => isLiveSessionForServer(session, serverDate, serverTime))
        .slice()
        .sort(compareAutoSessions);
      const primaryLive = availableLiveSessions[0] || null;
      const storedLock = readStoredSessionLock();
      const lockId = lockedSessionRef.current?.id ?? storedLock?.id;
      const validatedLock = availableLiveSessions.find(session => String(session.id) === String(lockId)) || null;

      setLiveSessions(availableLiveSessions);
      setRecommendedSessionId(primaryLive ? String(primaryLive.id) : "");
      lockedSessionRef.current = validatedLock;
      setLockedSession(validatedLock);
      if (validatedLock) {
        storeSessionLock(validatedLock);
        setSelectedSessionId(String(validatedLock.id));
      } else {
        if (lockId != null) storeSessionLock(null);
        setSelectedSessionId(current => availableLiveSessions.some(session => String(session.id) === String(current))
          ? current
          : primaryLive ? String(primaryLive.id) : "");
      }
      setSessionsValidated(true);
      setAttendanceSummaryRows(buildAttendanceSummaryRows(sessions, serverDate, serverTime));
      setAttendanceSummaryTotal(Number(data.summary?.unique_member_count || 0));
      setAttendanceSummaryError("");
    } catch (error) {
      console.error("[SESSIONS] Failed to load attendance summary:", error);
      setAttendanceSummaryError("summary_unavailable");
    } finally {
      setAttendanceSummaryLoading(false);
    }
  }, []);

  useEffect(() => {
    const handleStorage = (event) => {
      if (event.key !== KIOSK_API_KEY_STORAGE) return;
      setCredentialError(getKioskHeaders() ? "" : KIOSK_SETUP_MESSAGE);
    };
    window.addEventListener("storage", handleStorage);
    return () => window.removeEventListener("storage", handleStorage);
  }, []);

  // Sync Logic
  const performSync = useCallback(async () => {
    if (syncInFlightRef.current || !navigator.onLine) return;
    const headers = getKioskHeaders();
    if (!headers) {
      setCredentialError(KIOSK_SETUP_MESSAGE);
      setSyncError(KIOSK_SETUP_MESSAGE);
      return;
    }

    syncInFlightRef.current = true;
    setIsSyncing(true);
    try {
      const queue = await db.sync_queue.toArray();
      if (queue.length === 0) {
        setPendingCount(0);
        setSyncError("");
        return;
      }

      console.log(`[SYNC] Attempting to sync ${queue.length} records...`);
      
      const res = await fetch(API_SYNC, {
        method: "POST",
        headers,
        body: JSON.stringify({ records: queue }),
      });

      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(data.message || `Sync failed with status ${res.status}.`);
      }

      const syncedUuids = Array.isArray(data.synced_uuids) ? data.synced_uuids : [];
      const syncedIds = queue
        .filter(item => syncedUuids.includes(item.sync_id))
        .map(item => item.id);

      if (syncedIds.length) {
        await db.sync_queue.bulkDelete(syncedIds);
      }

      const remaining = await db.sync_queue.count();
      const failedCount = queue.length - syncedIds.length;
      console.log(`[SYNC] ${data.processed_count || 0} processed, ${syncedIds.length} cleared, ${remaining} pending.`);
      setPendingCount(remaining);
      setSyncError(failedCount > 0 ? `${failedCount} offline check-in(s) were not accepted and remain queued.` : "");
      if ((data.processed_count || 0) > 0 || syncedIds.length > 0) {
        refreshAttendanceSummary();
        fetchTodaySessionRosters();
      }
    } catch (err) {
      console.error("[SYNC ERROR]", err);
      setSyncError(`Offline sync failed: ${err.message}. Queued check-ins were retained.`);
      setPendingCount(await db.sync_queue.count());
    } finally {
      syncInFlightRef.current = false;
      setIsSyncing(false);
    }
  }, [refreshAttendanceSummary, fetchTodaySessionRosters]);

  // Network Listeners
  useEffect(() => {
    const checkStatus = async () => {
      // Also check if public attendance is still enabled
      checkEnabledStatus();

      if (!navigator.onLine) {
        setIsOnline(false);
        return;
      }

      try {
        const response = await fetch('/api/system/status');
        if (response.ok) {
          setIsOnline(true);
          performSync();
        } else {
          setIsOnline(false);
        }
      } catch (error) {
        setIsOnline(false);
      }
    };

    const handleOnline = () => {
      checkStatus();
    };
    const handleOffline = () => setIsOnline(false);

    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
    
    // Initial sync and count check
    db.sync_queue.count().then(setPendingCount);
    checkStatus();

    // Polling health check every 15 seconds
    const interval = setInterval(checkStatus, 15000);

    refreshAttendanceSummary();
    fetchRecentScans();
    fetchTodaySessionRosters();
    fetchMonthCelebrants();
    fetchRandomVerse();
    const summaryInterval = setInterval(refreshAttendanceSummary, 10000);
    const rosterInterval = setInterval(fetchTodaySessionRosters, 10000);
    const verseInterval = setInterval(() => fetchRandomVerse(true), 600000); // every 10 minutes
    const birthdaysInterval = setInterval(fetchMonthCelebrants, 300000); // every 5 minutes

    return () => {
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
      clearInterval(interval);
      clearInterval(summaryInterval);
      clearInterval(rosterInterval);
      clearInterval(verseInterval);
      clearInterval(birthdaysInterval);
    };
  }, [performSync, refreshAttendanceSummary, fetchTodaySessionRosters, checkEnabledStatus, fetchRandomVerse, fetchMonthCelebrants]);

  // Auto-submit after 500ms of inactivity (Desktop Only)
  useEffect(() => {
    if (isMobile) return;
    if (scanBuffer.trim().length > 0 && isEnabled) {
      if (autoSubmitTimerRef.current) clearTimeout(autoSubmitTimerRef.current);
      
      autoSubmitTimerRef.current = setTimeout(() => {
        const code = scanBuffer.trim();
        setScanBuffer(""); // Clear buffer immediately
        handleScan(code);
      }, 500);
    }
    
    return () => {
      if (autoSubmitTimerRef.current) clearTimeout(autoSubmitTimerRef.current);
    };
  }, [scanBuffer, isEnabled, isMobile]);

  // Refocus search helper (Desktop Only)
  const refocusSearch = useCallback(() => {
    if (!isMobile && isEnabled && searchRef.current && document.activeElement !== searchRef.current) {
      searchRef.current.focus();
    }
  }, [isEnabled, isMobile]);

  // Aggressive focus management (Desktop Only)
  useEffect(() => {
    if (!isEnabled || isMobile) return;
    
    refocusSearch();
    
    const interval = setInterval(refocusSearch, 1000);
    const onClick = (e) => {
      if (e.target.closest('select') || e.target.closest('button') || e.target.closest('input')) return;
      refocusSearch();
    };
    
    const input = searchRef.current;
    const onBlur = () => setTimeout(refocusSearch, 10);
    
    window.addEventListener("click", onClick);
    input?.addEventListener("blur", onBlur);
    
    return () => {
      window.removeEventListener("click", onClick);
      input?.removeEventListener("blur", onBlur);
      clearInterval(interval);
    };
  }, [refocusSearch, isEnabled, isMobile]);

  useEffect(() => {
    if (!isMobile) {
      setMobileActionsVisible(false);
      return undefined;
    }

    const showMobileActions = () => {
      setMobileActionsVisible(true);
      if (mobileActionsTimerRef.current) clearTimeout(mobileActionsTimerRef.current);
      mobileActionsTimerRef.current = setTimeout(() => {
        setMobileActionsVisible(false);
      }, 5000);
    };

    const touchOptions = { passive: true };
    window.addEventListener("touchstart", showMobileActions, touchOptions);
    window.addEventListener("mousemove", showMobileActions);

    return () => {
      window.removeEventListener("touchstart", showMobileActions, touchOptions);
      window.removeEventListener("mousemove", showMobileActions);
      if (mobileActionsTimerRef.current) clearTimeout(mobileActionsTimerRef.current);
    };
  }, [isMobile]);

  useEffect(() => {
    if (!isMobile) {
      setIsQrSupported(false);
      return undefined;
    }

    const hasCamera = Boolean(navigator.mediaDevices?.getUserMedia);
    if (!hasCamera) {
      setIsQrSupported(false);
      return undefined;
    }

    if (typeof window !== "undefined" && typeof window.jsQR === "function") {
      setIsQrSupported(true);
      return undefined;
    }

    let cancelled = false;
    const BarcodeDetectorApi = typeof window !== "undefined" ? window.BarcodeDetector : null;
    if (BarcodeDetectorApi && typeof BarcodeDetectorApi.getSupportedFormats === "function") {
      BarcodeDetectorApi.getSupportedFormats()
        .then(formats => {
          if (!cancelled) setIsQrSupported(formats.includes("qr_code"));
        })
        .catch(() => {
          if (!cancelled) setIsQrSupported(false);
        });
    } else if (BarcodeDetectorApi) {
      setIsQrSupported(true);
    } else {
      setIsQrSupported(false);
    }

    return () => {
      cancelled = true;
    };
  }, [isMobile]);

  const stopQrScanner = useCallback((shouldUpdate = true) => {
    if (qrScanFrameRef.current) {
      cancelAnimationFrame(qrScanFrameRef.current);
      qrScanFrameRef.current = null;
    }

    if (qrStreamRef.current) {
      qrStreamRef.current.getTracks().forEach(track => track.stop());
      qrStreamRef.current = null;
    }

    if (qrVideoRef.current) {
      qrVideoRef.current.pause();
      qrVideoRef.current.srcObject = null;
    }

    if (shouldUpdate) {
      setIsQrScanning(false);
      setQrScannerMessage("Point the camera at the QR code");
      refocusSearch();
    }
  }, [refocusSearch]);

  const decodeQrFrame = useCallback(async (video) => {
    if (typeof window !== "undefined" && "BarcodeDetector" in window) {
      if (!qrDetectorRef.current) {
        qrDetectorRef.current = new window.BarcodeDetector({ formats: ["qr_code"] });
      }

      const results = await qrDetectorRef.current.detect(video);
      if (results?.[0]?.rawValue) return results[0].rawValue;
    }

    if (typeof window !== "undefined" && typeof window.jsQR === "function") {
      const width = video.videoWidth;
      const height = video.videoHeight;
      if (!width || !height) return "";

      let canvas = qrCanvasRef.current;
      if (!canvas) {
        canvas = document.createElement("canvas");
        qrCanvasRef.current = canvas;
      }

      canvas.width = width;
      canvas.height = height;

      const context = canvas.getContext("2d", { willReadFrequently: true });
      if (!context) return "";

      context.drawImage(video, 0, 0, width, height);
      const imageData = context.getImageData(0, 0, width, height);
      const result = window.jsQR(imageData.data, width, height, {
        inversionAttempts: "dontInvert"
      });

      return result?.data || "";
    }

    return "";
  }, []);

  const showOverlay = (newState, duration, onEnd) => {
    clearInterval(countdownRef.current);
    clearTimeout(timerRef.current);
    
    const secs = Math.round(duration / 1000);
    setCountdown(secs);
    setState(newState);

    let remaining = secs;
    countdownRef.current = setInterval(() => {
      remaining -= 1;
      setCountdown(remaining);
      if (remaining <= 0) clearInterval(countdownRef.current);
    }, 1000);

    timerRef.current = setTimeout(() => {
      setState(current => current === newState ? "idle" : current);
      if (onEnd) onEnd();
      refocusSearch();
    }, duration);
  };

  const showBirthdayThenWelcome = (celebration) => {
    if (!celebration) {
      showOverlay("welcome", WELCOME_DURATION, () => setMember(null));
      return;
    }
    setBirthday(celebration);
    playKioskSound("birthday");
    showOverlay("birthday", WELCOME_DURATION, () => {
      setBirthday(null);
      showOverlay("welcome", WELCOME_DURATION, () => {
        setMember(null);
        stopBirthdayMelody();
      });
    });
  };

  const playKioskSound = (kind) => {
    if (soundMuted) return;
    try {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) return;
      if (!birthdayAudioRef.current) birthdayAudioRef.current = new AudioCtx();
      const ctx = birthdayAudioRef.current;
      if (ctx.state === "suspended") { ctx.resume(); }
      if (ctx.state !== "running") return;

      const playTone = (freq, start, dur, type = "sine", gain = 0.2) => {
        const osc = ctx.createOscillator();
        const g = ctx.createGain();
        osc.type = type;
        osc.frequency.value = freq;
        g.gain.setValueAtTime(0.0001, start);
        g.gain.exponentialRampToValueAtTime(gain, start + 0.03);
        g.gain.setValueAtTime(gain, start + dur - 0.08);
        g.gain.exponentialRampToValueAtTime(0.0001, start + dur);
        osc.connect(g);
        g.connect(ctx.destination);
        osc.start(start);
        osc.stop(start + dur + 0.05);
      };

      const t0 = ctx.currentTime + 0.05;

      if (kind === "birthday") {
        const BEAT = 0.3;
        const NOTE = (freq, beats) => [freq, beats];
        const melody = [
          NOTE(392.00, 0.75), NOTE(392.00, 0.25), NOTE(440.00, 1), NOTE(392.00, 1), NOTE(523.25, 1), NOTE(493.88, 2),
          NOTE(392.00, 0.75), NOTE(392.00, 0.25), NOTE(440.00, 1), NOTE(392.00, 1), NOTE(587.33, 1), NOTE(523.25, 2),
          NOTE(392.00, 0.75), NOTE(392.00, 0.25), NOTE(783.99, 1), NOTE(659.25, 1), NOTE(523.25, 1), NOTE(493.88, 1), NOTE(440.00, 2),
          NOTE(698.46, 0.75), NOTE(698.46, 0.25), NOTE(659.25, 1), NOTE(523.25, 1), NOTE(587.33, 1), NOTE(523.25, 2),
        ];

        let t = t0;
        melody.forEach(([freq, beats]) => {
          const dur = beats * BEAT;
          playTone(freq, t, dur, "triangle", 0.22);
          t += dur;
        });
        return;
      }

      if (kind === "success") {
        playTone(659.25, t0, 0.15, "triangle", 0.22);
        playTone(783.99, t0 + 0.16, 0.25, "triangle", 0.22);
      } else if (kind === "duplicate") {
        playTone(523.25, t0, 0.18, "sine", 0.16);
        playTone(523.25, t0 + 0.3, 0.18, "sine", 0.16);
      } else if (kind === "error") {
        playTone(196.0, t0, 0.28, "sawtooth", 0.12);
        playTone(146.83, t0 + 0.32, 0.4, "sawtooth", 0.12);
      }
    } catch (err) {
      console.warn("[SOUND]", err);
    }
  };

  const stopBirthdayMelody = () => {
    try {
      if (birthdayAudioRef.current) {
        birthdayAudioRef.current.close();
        birthdayAudioRef.current = null;
      }
    } catch (err) {
      console.warn("[BIRTHDAY SOUND]", err);
    }
  };

  const cacheMemberForOffline = (memberRecord) => {
    const code = memberCode(memberRecord);
    if (!memberRecord?.id || !code) return;

    db.members_cache.put({
      member_code: code,
      id: memberRecord.id,
      first_name: memberRecord.first_name,
      last_name: memberRecord.last_name,
      profile_photo_url: memberRecord.profile_photo_url || memberRecord.photo || memberRecord.image_url || null,
    });
  };

  const showSessionLockRequired = () => {
    playKioskSound("error");
    setErrorMsg(SESSION_LOCK_REQUIRED_MESSAGE);
    showOverlay("error", WELCOME_DURATION, () => setErrorMsg(""));
  };

  const handleSessionLock = () => {
    const selected = liveSessions.find(session => String(session.id) === String(selectedSessionId));
    if (!selected) {
      showSessionLockRequired();
      return;
    }
    lockedSessionRef.current = selected;
    setLockedSession(selected);
    storeSessionLock(selected);
    refocusSearch();
  };

  const handleSessionUnlock = () => {
    lockedSessionRef.current = null;
    setLockedSession(null);
    storeSessionLock(null);
    setSelectedSessionId(recommendedSessionId);
    refocusSearch();
  };

  const handleOfflineSubmission = async (input, selectedSession) => {
    if (!selectedSession) {
      showSessionLockRequired();
      return;
    }

    const inputIsMember = typeof input === "object" && input !== null;
    const code = inputIsMember ? memberCode(input) : String(input || "").trim();

    // 1. Try to find member in local cache
    let localMember = inputIsMember ? input : null;
    if (!localMember && code) {
      localMember = await db.members_cache.get(code);
    }
    
    // 2. If not found, use a placeholder or just the code
    const memberData = localMember ? {
      name: memberDisplayName(localMember),
      id: localMember.id,
      first_name: localMember.first_name,
      last_name: localMember.last_name,
      profile_photo_url: localMember.profile_photo_url || null,
    } : {
      name: `Member Code: ${code}`,
      id: null // Will need server lookup during sync if possible
    };

    // 3. Queue the sync record
    await db.sync_queue.add({
      sync_id: generateUUID(),
      member_id: memberData.id,
      member_code: code, // Backup in case ID is null
      session_id: selectedSession.id,
      created_at: new Date()
    });

    setPendingCount(prev => prev + 1);
    setIsOfflineRecord(true);
    playKioskSound("success");
    setMember(memberData);
    setLastTime(formatTime(new Date()));
    setRecentScans(prev => [{ name: memberData.name, time: formatTime(new Date()), ts: Date.now(), offline: true, profile_photo_url: memberData.profile_photo_url || null }, ...prev].slice(0, 10));
    showOverlay("welcome", WELCOME_DURATION, () => {
      setMember(null);
      setIsOfflineRecord(false);
    });
  };

  const handleScan = async (code) => {
    if (!code) return;
    const targetSession = lockedSessionRef.current;
    if (!targetSession) {
      showSessionLockRequired();
      return;
    }
    const headers = getKioskHeaders();
    if (!headers) {
      setCredentialError(KIOSK_SETUP_MESSAGE);
      setErrorMsg(KIOSK_SETUP_MESSAGE);
      showOverlay("error", WELCOME_DURATION, () => setErrorMsg(""));
      return;
    }
    stopBirthdayMelody();
    const scanId = ++lastScanIdRef.current;
    const offlineSession = targetSession;
    
    try {
      setState("loading");
      
      // Attempt online scan with timeout
      const res = await fetchWithTimeout(API_MEMBER_LOOKUP, {
        method: "POST",
        headers,
        body: JSON.stringify({ member_id: code.trim(), session_id: targetSession.id }),
      });
      
      const data = await res.json();
      
      // If a newer scan has started, ignore this result
      if (scanId !== lastScanIdRef.current) return;
      
      const result = data.data || data;

      if (!res.ok) {
        // Check if it's a duplicate (409)
        if (isDuplicateResponse(res, data)) {
          const duplicate = duplicateMetadata(data);
          playKioskSound("duplicate");
          setDuplicateName(duplicate.name);
          setDuplicateTime(duplicate.time);
          showOverlay("duplicate", WELCOME_DURATION, () => {
            setDuplicateName("");
            setDuplicateTime("");
          });
          return;
        }
        playKioskSound("error");
        throw new Error(data.message || data.error || "Scan failed");
      }

      // Success — cache member for future offline lookup
      const memberPhoto = result.profile_photo_url || result.member?.profile_photo_url || result.member?.photo || result.member?.image_url || null;
      if (result.member) {
        cacheMemberForOffline({ ...result.member, member_code: result.member.member_code || code.trim(), profile_photo_url: memberPhoto });
      }

      playKioskSound("success");
      setMember({ ...(result.member || {}), profile_photo_url: memberPhoto });
      setLastTime(result.attendance_time);
      setIsOfflineRecord(false);
      setRecentScans(prev => [{ name: memberDisplayName(result.member), time: result.attendance_time, ts: Date.now(), profile_photo_url: memberPhoto }, ...prev].slice(0, 10));
      refreshAttendanceSummary();
      fetchRecentScans();
      fetchTodaySessionRosters();
       showBirthdayThenWelcome(result.birthday);

    } catch (err) {
      if (scanId !== lastScanIdRef.current) return;
      
      // Check if we should fallback to offline
      if (err.name === 'AbortError' || !navigator.onLine || err.message === 'Failed to fetch') {
        console.warn("[OFFLINE] Connection slow or down, switching to local queue.");
        handleOfflineSubmission(code.trim(), offlineSession);
        return;
      }

      console.error("[SCAN ERROR]", err);
      setErrorMsg(err.message);
      showOverlay("error", WELCOME_DURATION, () => setErrorMsg(""));
    }
  };

  const handleMemberCheckIn = async (memberRecord) => {
    if (!memberRecord?.id) return;
    const targetSession = lockedSessionRef.current;
    if (!targetSession) {
      showSessionLockRequired();
      return;
    }
    const headers = getKioskHeaders();
    if (!headers) {
      setCredentialError(KIOSK_SETUP_MESSAGE);
      setErrorMsg(KIOSK_SETUP_MESSAGE);
      showOverlay("error", WELCOME_DURATION, () => setErrorMsg(""));
      return;
    }
    stopBirthdayMelody();
    const scanId = ++lastScanIdRef.current;
    const offlineSession = targetSession;

    try {
      setState("loading");

      const res = await fetchWithTimeout(API_MEMBER_CHECK_IN, {
        method: "POST",
        headers,
        body: JSON.stringify({ member_id: memberCode(memberRecord), session_id: targetSession.id }),
      });

      const data = await res.json();
      if (scanId !== lastScanIdRef.current) return;

      const result = data.data || data;

      if (!res.ok) {
        if (isDuplicateResponse(res, data)) {
          const duplicate = duplicateMetadata(data, memberDisplayName(memberRecord));
          playKioskSound("duplicate");
          setDuplicateName(duplicate.name);
          setDuplicateTime(duplicate.time);
          showOverlay("duplicate", WELCOME_DURATION, () => {
            setDuplicateName("");
            setDuplicateTime("");
          });
          return;
        }
        playKioskSound("error");
        throw new Error(data.message || data.error || "Check-in failed");
      }

      const checkedInMember = {
        ...(result.member || memberRecord),
        profile_photo_url: result.profile_photo_url || result.member?.profile_photo_url || result.member?.photo || result.member?.image_url || memberRecord?.profile_photo_url || null,
      };
      if (result.member) {
        cacheMemberForOffline(checkedInMember);
      }

      playKioskSound("success");
      setMember(checkedInMember);
      setLastTime(result.attendance_time || formatTime(new Date()));
      setIsOfflineRecord(false);
      setRecentScans(prev => [{ name: memberDisplayName(checkedInMember), time: result.attendance_time || formatTime(new Date()), ts: Date.now(), profile_photo_url: checkedInMember?.profile_photo_url || null }, ...prev].slice(0, 10));
      refreshAttendanceSummary();
      fetchRecentScans();
      fetchTodaySessionRosters();
       showBirthdayThenWelcome(result.birthday);
    } catch (err) {
      if (scanId !== lastScanIdRef.current) return;

      if (err.name === 'AbortError' || !navigator.onLine || err.message === 'Failed to fetch') {
        console.warn("[OFFLINE] Connection slow or down, saving selected member locally.");
        handleOfflineSubmission(memberRecord, offlineSession);
        return;
      }

      console.error("[CHECK-IN ERROR]", err);
      setErrorMsg(err.message);
      showOverlay("error", WELCOME_DURATION, () => setErrorMsg(""));
    }
  };

  const startMobileQrScan = useCallback(async () => {
    if (isQrScanning) return;
    if (!lockedSessionRef.current) {
      showSessionLockRequired();
      return;
    }

    if (!isSecureCameraContext()) {
      const httpsPort = "3443";
      const host = window.location.hostname;
      setErrorMsg(`Live QR scanning requires HTTPS. Open https://${host}:${httpsPort}/attendance/PublicAttendance.html on this device.`);
      showOverlay("error", WELCOME_DURATION, () => setErrorMsg(""));
      return;
    }

    try {
      setMobileActionsVisible(true);
      setQrScannerMessage("Opening camera...");
      const video = qrVideoRef.current;
      if (!video) throw new Error("QR scanner video target is unavailable.");

      setIsQrScanning(true);
      const stream = await openQrCameraStream();
      qrStreamRef.current = stream;
      video.srcObject = stream;
      await video.play();

      const canDecodeQr = await canDecodeQrWithBrowser();
      setIsQrSupported(canDecodeQr);
      if (!canDecodeQr) {
        setQrScannerMessage("Camera is open, but QR decoder failed to load. Refresh and try again.");
        return;
      }

      setQrScannerMessage("Point the camera at the QR code");

      const scanFrame = async () => {
        if (!qrStreamRef.current) return;

        if (video.readyState >= 2) {
          try {
            const decoded = await decodeQrFrame(video);
            if (decoded) {
              stopQrScanner();
              handleScan(decoded.trim());
              return;
            }
          } catch (error) {
            console.warn("[QR SCAN] Frame decode failed:", error);
          }
        }

        qrScanFrameRef.current = requestAnimationFrame(scanFrame);
      };

      qrScanFrameRef.current = requestAnimationFrame(scanFrame);
    } catch (error) {
      stopQrScanner();
      setQrScannerMessage("Point the camera at the QR code");

      const message = error?.name === "NotAllowedError"
        ? "Camera permission was denied. Please allow camera access to scan QR codes."
        : error?.name === "NotFoundError"
          ? "No camera was found on this device."
          : !isSecureCameraContext()
            ? "Live camera scanning requires HTTPS. Use the camera capture fallback or open this page over HTTPS."
            : "Unable to open the camera for QR scanning.";

      setErrorMsg(message);
      showOverlay("error", WELCOME_DURATION, () => setErrorMsg(""));
    }
  }, [decodeQrFrame, handleScan, isQrScanning, stopQrScanner]);

  const handleMemberSelect = (member) => {
    setSelectedMemberForConfirm(member);
    setSearchResults([]);
    setScanBuffer("");
    setState("confirming");
  };

  const handleConfirmAttendance = () => {
    if (!selectedMemberForConfirm) return;
    const memberToCheckIn = selectedMemberForConfirm;
    setSelectedMemberForConfirm(null);
    handleMemberCheckIn(memberToCheckIn);
  };

  const handleCancelConfirm = () => {
    setSelectedMemberForConfirm(null);
    setState("idle");
  };

  // QR scanner effect - Auto-start on desktop IDLE, Manual on mobile
  useEffect(() => {
    if (isMobile) return; // Never auto-start on mobile
    
    if (state === "idle" && lockedSession && isQrSupported && !isQrScanning) {
      startMobileQrScan();
    } else if (state !== "idle" && isQrScanning) {
      stopQrScanner();
    }
  }, [state, lockedSession, isQrSupported, isQrScanning, startMobileQrScan, stopQrScanner, isMobile]);

  useEffect(() => () => {
    clearTimeout(timerRef.current);
    clearInterval(countdownRef.current);
    stopQrScanner(false);
  }, [stopQrScanner]);

  if (isCheckingEnabled) {
    return (
      <div style={{
        display: "flex", alignItems: "center", justifyContent: "center",
        height: "100vh", width: "100vw", background: THEME.page,
        fontFamily: FONT_STACK, color: THEME.textMuted
      }}>
        Loading...
      </div>
    );
  }

  if (!isEnabled) {
    return (
      <div style={{
        display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center",
        height: "100vh", width: "100vw", background: THEME.page,
        fontFamily: FONT_STACK, textAlign: "center", padding: 20
      }}>
        <div style={{
          width: 80, height: 80, borderRadius: "0", background: "#fee2e2",
          display: "flex", alignItems: "center", justifyContent: "center",
          marginBottom: 24, color: "#dc2626"
        }}>
          <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
          </svg>
        </div>
        <h1 style={{ fontSize: 32, fontWeight: 700, color: THEME.text, margin: "0 0 12px" }}>
          Page Not Available
        </h1>
        <p style={{ fontSize: 18, color: THEME.textSecondary, maxWidth: 500, lineHeight: 1.6 }}>
          Public attendance is currently disabled. Please contact the administrator if you believe this is an error.
        </p>
      </div>
    );
  }

  return (
    <div className="public-attendance-root">
      <link rel="stylesheet" href="/assets/vendor/fonts/inter.css" />
      {(credentialError || syncError) && (
        <div
          role="alert"
          style={{
            position: "fixed", top: 10, left: "50%", transform: "translateX(-50%)",
            zIndex: 3000, width: "min(92vw, 760px)", padding: "10px 14px",
            border: "1px solid #dc2626", background: "#fef2f2", color: "#991b1b",
            fontFamily: FONT_STACK, fontSize: 12, fontWeight: 600, textAlign: "center",
            boxShadow: "0 4px 14px rgba(0,0,0,0.12)",
          }}
        >
          {credentialError || syncError}
        </div>
      )}

      {/* ── MOBILE HEADER (mobile only) ───────────────────────── */}
      {isMobile && (
        <header className="attendance-header">
          <div className="mobile-brand-group">
            <div className="mobile-brand-logo">
              {logoFailed ? (
                <span style={{ fontSize: 11, fontWeight: 800, color: "#fff" }}>WOH</span>
              ) : (
                <img src="/images/WOHLOGO.png" alt="WOH" onError={() => setLogoFailed(true)} />
              )}
            </div>
            <div className="mobile-brand-name-stack">
              <p className="mobile-brand-name">Attendance</p>
              <p className="mobile-brand-sub">Word of Hope Caloocan</p>
            </div>
          </div>
          <div className="mobile-header-right">
            {lockedSession && (
              <div className="mobile-live-badge">
                <span className="live-dot" />
                <span>Locked</span>
              </div>
            )}
            {pendingCount > 0 && (
              <span style={{
                fontSize: 9, fontWeight: 700, color: "#fff",
                background: "rgba(239,68,68,0.85)", padding: "3px 7px", borderRadius: 999,
                animation: "pulse 2s infinite", letterSpacing: "0.04em"
              }}>
                {isSyncing ? "SYNC" : `${pendingCount}`}
              </span>
            )}
            <ConnectionBadge isOnline={isOnline} />
          </div>
        </header>
      )}

      {/* ── BODY ──────────────────────────────────────────────── */}
      <div style={{ display: "flex", flexDirection: isMobile ? "column" : "row", flex: 1, overflow: isMobile ? "visible" : "hidden" }}>
        {!isMobile && (
          <aside className="attendance-aside">
            {/* Logo */}
            <div className="logo-container">
              {logoFailed ? (
                <span style={{ fontFamily: FONT_STACK, fontSize: 22, fontWeight: 800, color: "#ffffff" }}>WOH</span>
              ) : (
                <img
                  src="/images/WOHLOGO.png"
                  alt="Word of Hope Logo"
                  onError={() => setLogoFailed(true)}
                  style={{ width: "100%", height: "100%", objectFit: "contain", padding: 4, borderRadius: "50%" }}
                />
              )}
            </div>

            {/* Church name */}
            <p className="aside-church-name">Word of Hope<br/>Caloocan</p>

            {/* Divider */}
            <div className="aside-divider" />

            {/* Live Service */}
            <div className="aside-live-section">
              <div className="aside-live-badge">
                <span className="live-dot" />
                <span>Live Service</span>
              </div>
              {liveSessions.length === 0 ? (
                <p className="aside-live-status">No service is currently running</p>
              ) : (
                liveSessions.map(session => (
                  <div key={session.id} className="aside-session-card">
                    <div className="aside-session-name">{sessionName(session)}{lockedSession && String(session.id) === String(lockedSession.id) ? " | LOCKED" : ""}</div>
                    <div className="aside-session-time">{sessionTimeLabel(session)}</div>
                  </div>
                ))
              )}
            </div>

            {/* Total Attendance Counter */}
            <div className="aside-attendance-counter">
              <div className="aside-counter-label">Total Unique Attendance</div>
              <div className="aside-counter-number">{Number(attendanceSummaryTotal || 0)}</div>
            </div>

            {/* Welcome message */}
            <div className="aside-welcome-msg">
              <p className="aside-welcome-line1">We're glad</p>
              <p className="aside-welcome-line2">you're here!</p>
              <p className="aside-welcome-sub">Thank you for joining us<br/>today. God bless you!</p>

              {monthCelebrants.length > 0 && (
                <div className="aside-celebrants">
                  <div className="aside-celebrants-title">🎂 {celebrantMonth} Birthdays</div>
                  <div className="aside-celebrants-list">
                    {monthCelebrants.map((c) => (
                      <div key={c.member_code || `${c.day}-${c.name}`} className="aside-celebrant">
                        <span className="aside-celebrant-day">{c.day}</span>
                        <span className="aside-celebrant-name">{c.name || c.member_code}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>

            {/* Spacer */}
            <div style={{ flex: 1 }} />

            {/* Social links */}
            <table className="aside-social-table">
              <tbody>
                <tr>
                  <td className="aside-social-cell-icon">
                    <a href="https://www.facebook.com/wohcaloocan" target="_blank" rel="noopener noreferrer" aria-label="Facebook">
                      <svg width="15" height="15" viewBox="0 0 24 24" fill="rgba(255,255,255,0.72)">
                        <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>
                      </svg>
                    </a>
                  </td>
                  <td className="aside-social-cell-link">
                    <a href="https://www.facebook.com/wohcaloocan" target="_blank" rel="noopener noreferrer">facebook.com/wohcaloocan</a>
                  </td>
                </tr>
                <tr>
                  <td className="aside-social-cell-icon">
                    <a href="https://www.youtube.com/channel/UCUuuEbx8ftDnRhPfkVwujow" target="_blank" rel="noopener noreferrer" aria-label="YouTube">
                      <svg width="15" height="15" viewBox="0 0 24 24">
                        <path fill="rgba(255,255,255,0.72)" d="M22.54 6.42a2.78 2.78 0 0 0-1.95-1.95C18.88 4 12 4 12 4s-6.88 0-8.59.47a2.78 2.78 0 0 0-1.95 1.95C1 8.13 1 12 1 12s0 3.87.46 5.58A2.78 2.78 0 0 0 3.41 19.53C5.12 20 12 20 12 20s6.88 0 8.59-.47a2.78 2.78 0 0 0 1.95-1.95C23 15.87 23 12 23 12s0-3.87-.46-5.58z"/>
                        <polygon fill="#0d1b3e" points="9.75 15.02 15.5 12 9.75 8.98 9.75 15.02"/>
                      </svg>
                    </a>
                  </td>
                  <td className="aside-social-cell-link">
                    <a href="https://www.youtube.com/channel/UCUuuEbx8ftDnRhPfkVwujow" target="_blank" rel="noopener noreferrer">WOH Caloocan</a>
                  </td>
                </tr>
              </tbody>
            </table>
          </aside>
        )}

        {/* ── MAIN CONTENT ────────────────────────────────────── */}
        <main className="main-content">

          {/* Desktop page header: title + date/time pills (inside main, no top bar) */}
          {!isMobile && (
            <div className="page-header">
              <div className="attendance-title-group">
                <span className="attendance-title">Attendance</span>
                <span className="attendance-subtitle">Thank you for being with us today.</span>
              </div>
              <div className="page-header-right">
                <div className="datetime-pill">
                  <div className="datetime-pill-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                      <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                  </div>
                  <div>
                    <div className="datetime-pill-label">{formatWeekday(now).toUpperCase()}</div>
                    <div className="datetime-pill-value">{formatDate(now)}</div>
                  </div>
                </div>
                <div className="datetime-pill">
                  <div className="datetime-pill-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                      <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                    </svg>
                  </div>
                  <div className="datetime-pill-time">{formatTime(now)}</div>
                </div>
                {pendingCount > 0 && (
                  <span style={{
                    fontSize: 10, fontWeight: 600, color: "#ef4444",
                    background: "#fee2e2", padding: "4px 10px", borderRadius: 6,
                    animation: "pulse 2s infinite"
                  }}>
                    {isSyncing ? "SYNCING..." : `${pendingCount} PENDING`}
                  </span>
                )}
                <ConnectionBadge isOnline={isOnline} />
              </div>
            </div>
          )}

          {/* Date & Time Bar — mobile only; desktop shows it in the page header */}
          {isMobile && (
            <div className="datetime-bar">
              <div style={{ display: "flex", alignItems: "center", justifyContent: "center", gap: 6, flexWrap: "wrap" }}>
                <p className="datetime-text" style={{ fontSize: 13 }}>{formatWeekday(now)}</p>
                <p className="datetime-text" style={{ fontSize: 13 }}>{formatDate(now)}</p>
                <p className="datetime-text" style={{ fontSize: 13 }}>{formatTime(now)}</p>
              </div>
              {!isOnline && (
                <>
                  <div style={{ width: 1, height: 24, background: THEME.border }} />
                  <div style={{ fontSize: 10, color: "#991b1b", background: "#fef2f2", border: "1px solid #fecaca", borderRadius: 0, padding: "4px 8px", letterSpacing: "0.06em", fontWeight: 600 }}>
                    OFFLINE READY
                  </div>
                </>
              )}
            </div>
          )}

          {isMobile && (
            <div className={`mobile-attend-for${lockedSession ? " mobile-attend-for--active" : " mobile-attend-for--empty"}`}>
              {lockedSession ? (
                <>
                  <span className="mobile-attend-for-label">Locked attendance session</span>
                  <span className="mobile-attend-for-value">{exactSessionLabel(lockedSession)}</span>
                </>
              ) : (
                <span className="mobile-attend-for-empty">NO SESSION LOCKED - SCANNING BLOCKED</span>
              )}
            </div>
          )}

          <SessionLockPanel
            sessions={liveSessions}
            lockedSession={lockedSession}
            recommendedSessionId={recommendedSessionId}
            selectedId={selectedSessionId}
            onSelect={setSelectedSessionId}
            onLock={handleSessionLock}
            onUnlock={handleSessionUnlock}
            loading={!sessionsValidated && attendanceSummaryLoading}
          />

          {/* Search / Scan Bar */}
          <div className="scan-bar">
            {isMobile ? (
              /* Mobile: search icon + text input + action buttons */
              <>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke={THEME.textMuted} strokeWidth="2.5" style={{ flexShrink: 0, marginLeft: 4 }}>
                  <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
                </svg>
                <input
                  className="scan-input"
                  ref={searchRef}
                  value={scanBuffer}
                  onChange={e => setScanBuffer(e.target.value)}
                  onKeyDown={e => {
                    if (e.key === "Enter" && scanBuffer.trim() && !isMobile) {
                      if (autoSubmitTimerRef.current) clearTimeout(autoSubmitTimerRef.current);
                      handleScan(scanBuffer.trim());
                      setScanBuffer("");
                    }
                  }}
                  placeholder={lockedSession ? "Search name or scan code..." : "Lock a live session before scanning"}
                  autoFocus={false}
                />
                {isSearching && (
                  <div style={{ position: "absolute", right: 100, top: "50%", transform: "translateY(-50%)" }}>
                    <div className="spinner-small"></div>
                  </div>
                )}
                <div className="mobile-scan-bar-actions">
                  <button type="button" className="mobile-scan-bar-btn" onClick={startMobileQrScan}>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                      <rect x="3" y="3" width="7" height="7" />
                      <rect x="14" y="3" width="7" height="7" />
                      <rect x="14" y="14" width="7" height="7" />
                      <rect x="3" y="14" width="7" height="7" />
                    </svg>
                  </button>
                </div>
              </>
            ) : (
              /* Desktop: styled scan bar with icon circle + title + hidden capture input */
              <>
                <div className="scan-bar-icon-wrap">
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#1e3a8a" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
                    <rect x="3" y="14" width="7" height="7" rx="1"/>
                    <path d="M14 14h1v1h-1zM17 14h1v1h-1zM14 17h1v1h-1zM17 17h1v3h3v-3h-3M20 14h1v1h-1z"/>
                  </svg>
                </div>
                <div className="scan-bar-text-group">
                  <p className="scan-bar-title">Scan to Check In</p>
                  <input
                    className="scan-input"
                    ref={searchRef}
                    value={scanBuffer}
                    onChange={e => setScanBuffer(e.target.value)}
                    onKeyDown={e => {
                      if (e.key === "Enter" && scanBuffer.trim()) {
                        if (autoSubmitTimerRef.current) clearTimeout(autoSubmitTimerRef.current);
                        handleScan(scanBuffer.trim());
                        setScanBuffer("");
                      }
                    }}
                    placeholder={!lockedSession ? "Operator must lock a live session before scanning." : isOnline ? "Hold your QR code in front of the camera to record your attendance." : "Kiosk is OFFLINE - scanning locally..."}
                    autoFocus
                  />
                </div>
                <div className="scan-bar-divider" />
                {/* QR illustration */}
                <div className="scan-bar-qr-art">
                  <svg width="52" height="52" viewBox="0 0 64 64" fill="none">
                    {/* Phone outline */}
                    <rect x="16" y="6" width="32" height="52" rx="4" stroke="#1e3a8a" strokeWidth="2.5"/>
                    <rect x="21" y="14" width="22" height="22" rx="2" fill="#dbeafe" stroke="#1e3a8a" strokeWidth="1.5"/>
                    {/* Mini QR pattern */}
                    <rect x="24" y="17" width="4" height="4" rx="0.5" fill="#1e3a8a"/>
                    <rect x="33" y="17" width="4" height="4" rx="0.5" fill="#1e3a8a"/>
                    <rect x="24" y="26" width="4" height="4" rx="0.5" fill="#1e3a8a"/>
                    <rect x="29" y="21" width="2" height="2" fill="#1e3a8a"/>
                    <rect x="33" y="24" width="4" height="4" rx="0.5" fill="#1e3a8a"/>
                    <rect x="29" y="26" width="4" height="2" fill="#1e3a8a"/>
                    {/* Scan corners */}
                    <path d="M5 18v-6h6" stroke="#1e3a8a" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/>
                    <path d="M5 46v6h6" stroke="#1e3a8a" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/>
                  </svg>
                </div>
              </>
            )}
            <SearchResults
              results={searchResults}
              onSelect={handleMemberSelect}
              visible={isMobile && scanBuffer.trim().length >= 2}
            />
          </div>

          {/* Content Zone — Video / Welcome / Error / Duplicate / Confirm */}
          <div className="content-zone">

            {/* Desktop: left column = video + how-to strip; right column = recent scan + verse */}
            {!isMobile && (
              <div className="content-zone-inner">

                {/* LEFT — attend banner + video + how-to strip */}
                <div className="content-zone-left">
                  <div className={`desktop-attend-for${lockedSession ? " desktop-attend-for--active" : " desktop-attend-for--empty"}`}>
                    {lockedSession ? (
                      <>
                        <span className="desktop-attend-for-label">Locked Attendance Session:</span>
                        <span className="desktop-attend-for-value" title={exactSessionLabel(lockedSession)}>{exactSessionLabel(lockedSession)}</span>
                      </>
                    ) : (
                      <span className="desktop-attend-for-empty">NO SESSION LOCKED - SCANNING BLOCKED</span>
                    )}
                  </div>
                  <div className="content-zone-video">
                    <YouTubePlayer visible={state === "idle" || state === "loading"} style={{ opacity: state === "welcome" ? 0 : 1, transition: "opacity 0.5s ease" }} />
                     <WelcomeScreen
                       member={member}
                       visible={state === "welcome"}
                      countdown={countdown}
                      time={lastTime}
                      isOffline={isOfflineRecord}
                       isMobile={isMobile}
                     />
                     <BirthdayScreen
                       celebration={birthday}
                       visible={state === "birthday"}
                       countdown={countdown}
                     />
                    <ErrorScreen
                      message={errorMsg}
                      visible={state === "error"}
                      countdown={countdown}
                    />
                    <DuplicateScreen
                      memberName={duplicateName}
                      previousTime={duplicateTime}
                      visible={state === "duplicate"}
                      countdown={countdown}
                    />
                  </div>

                  {/* How-to strip — horizontal, below video */}
                  <div className="checkin-strip">
                    <div className="checkin-strip-step">
                      <div className="checkin-strip-icon">1</div>
                      <div className="checkin-strip-text">
                        <p className="checkin-strip-label">Open your card</p>
                        <p className="checkin-strip-sub">from your device</p>
                      </div>
                    </div>
                    <div className="checkin-strip-arrow"></div>
                    <div className="checkin-strip-step">
                      <div className="checkin-strip-icon">2</div>
                      <div className="checkin-strip-text">
                        <p className="checkin-strip-label">Hold up to scanner</p>
                        <p className="checkin-strip-sub">point at the camera</p>
                      </div>
                    </div>
                    <div className="checkin-strip-arrow"></div>
                    <div className="checkin-strip-step">
                      <div className="checkin-strip-icon">3</div>
                      <div className="checkin-strip-text">
                        <p className="checkin-strip-label">Welcome</p>
                        <p className="checkin-strip-sub">You're all set!</p>
                      </div>
                    </div>
                  </div>
                </div>

                {/* RIGHT — recent scans list + verse */}
                <div className="checkin-panel">

                  {/* Recent scans list */}
                  <div className="recent-scan-box">
                    {recentScans.length === 0 ? (
                      <p className="recent-scan-empty">Waiting for scan…</p>
                    ) : (
                      <div className="recent-scan-list">
                        {recentScans.slice(0, 10).map((scan, i) => (
                          <div key={i} className={`recent-scan-row${i === 0 ? " recent-scan-row--latest" : ""}`}>
                            <div className="recent-scan-avatar-sm">
                              {(scan.name || "?")[0].toUpperCase()}
                              {scan.profile_photo_url ? (
                                <img
                                  src={scan.profile_photo_url}
                                  alt=""
                                  className="pa-avatar__img"
                                  referrerPolicy="no-referrer"
                                  loading="lazy"
                                  onError={(e) => { e.currentTarget.style.display = "none"; }}
                                />
                              ) : null}
                            </div>
                            <div className="recent-scan-info">
                              <p className="recent-scan-name">{scan.name}</p>
                              <p className="recent-scan-time">{relativeTime(scan)}{scan.offline ? " · offline" : ""}</p>
                            </div>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>

                  {/* Verse */}
                  {verse && (
                    <div className="checkin-verse">
                      <p className="checkin-verse-text">"{verse.text}"</p>
                      <p className="checkin-verse-ref">{verse.ref}</p>
                    </div>
                  )}

                </div>
              </div>
            )}

            {/* Mobile: idle screen */}
            {isMobile && (
              <MobileIdleScreen
                visible={state === "idle" && !isQrScanning}
                total={attendanceSummaryTotal}
                sessions={todaySessionRosters}
                liveSessionId={lockedSession?.id || recommendedSessionId}
                loading={todayRostersLoading}
              />
            )}

            {/* Mobile: feedback screens (welcome / error / duplicate) — mirrors desktop flow */}
            {isMobile && (
              <>
                 <WelcomeScreen
                   member={member}
                   visible={state === "welcome"}
                  countdown={countdown}
                  time={lastTime}
                  isOffline={isOfflineRecord}
                   isMobile={isMobile}
                 />
                 <BirthdayScreen
                   celebration={birthday}
                   visible={state === "birthday"}
                   countdown={countdown}
                 />
                <ErrorScreen
                  message={errorMsg}
                  visible={state === "error"}
                  countdown={countdown}
                />
                <DuplicateScreen
                  memberName={duplicateName}
                  previousTime={duplicateTime}
                  visible={state === "duplicate"}
                  countdown={countdown}
                />
              </>
            )}

            <ConfirmScreen
              member={selectedMemberForConfirm}
              visible={state === "confirming"}
              onConfirm={handleConfirmAttendance}
              onCancel={handleCancelConfirm}
            />

            {/* Loading indicator */}
            {state === "loading" && (
              <div style={{
                position: "absolute", inset: 0,
                display: "flex", alignItems: "center", justifyContent: "center",
                background: "color-mix(in srgb, var(--color-surface) 82%, transparent)",
                zIndex: 10,
              }}>
                <p style={{ fontSize: 16, color: THEME.textMuted, fontFamily: FONT_STACK }}>
                  {isOnline ? "Processing..." : "Saving locally..."}
                </p>
              </div>
            )}

          </div>

          {/* Footer bar — desktop only */}
          {!isMobile && (
            <div className="attendance-footer-bar">
              <div className="footer-bar-left">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="#e11d48" stroke="none">
                  <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                </svg>
                <span>Building a community of faith, hope, and love.</span>
              </div>
              <span className="footer-bar-right">WWW.WOHCALOOCAN.ORG</span>
            </div>
          )}
        </main>
      </div>

      {isQrScanning && (
        <div className="qr-overlay-fullscreen">
          <p style={{
            margin: 0,
            color: "#ffffff",
            fontSize: 14,
            fontWeight: 600,
            textAlign: "center",
          }}>
            {qrScannerMessage}
          </p>
          <button
            type="button"
            onClick={stopQrScanner}
            style={{
              border: `1px solid ${THEME.border}`,
              background: THEME.surface,
              color: THEME.text,
              borderRadius: 0,
              padding: "10px 18px",
              fontFamily: FONT_STACK,
              fontSize: 14,
              fontWeight: 600,
              cursor: "pointer",
            }}
          >
            Cancel
          </button>
        </div>
      )}
      <video
        ref={qrVideoRef}
        muted
        playsInline
        autoPlay
        className="qr-video-scanner"
        aria-hidden={!isQrScanning}
        style={{
          opacity: isQrScanning ? 1 : 0,
        }}
      />
    </div>
  );
}
