export function formatDate(value) {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;

  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${date.getFullYear()}-${month}-${day}`;
}

export function parseISODate(value) {
  if (!value) return null;
  const date = new Date(`${value}T00:00:00`);
  return Number.isNaN(date.getTime()) ? null : date;
}

export function formatTime12(value) {
  if (!value) return '';
  const match = String(value)
    .trim()
    .match(/^(\d{1,2}):(\d{2})(?::\d{2})?\s*(AM|PM)?$/i);
  if (!match) return String(value);

  const minutes = match[2];
  const explicitPeriod = match[3] ? match[3].toUpperCase() : null;
  if (explicitPeriod) {
    return `${parseInt(match[1], 10)}:${minutes} ${explicitPeriod}`;
  }

  const hours = parseInt(match[1], 10);
  const period = hours >= 12 ? 'PM' : 'AM';
  const displayHour = hours % 12 || 12;
  return `${displayHour}:${minutes} ${period}`;
}

export function parseTime24(value) {
  const match = String(value || '')
    .trim()
    .match(/^(\d{1,2}):(\d{2})/);
  if (!match) return null;

  let hours = parseInt(match[1], 10);
  const period = hours >= 12 ? 'PM' : 'AM';
  hours = hours % 12 || 12;
  return { hour: String(hours), minute: match[2], period };
}

export function composeTime24(hour, minute, period) {
  let hours = (parseInt(hour, 10) || 0) % 12;
  if (period === 'PM') hours += 12;
  return `${String(hours).padStart(2, '0')}:${String(minute || 0).padStart(2, '0')}`;
}

export function getFullName(member) {
  if (!member) return '';
  if (member.name) return member.name;

  return [member.first_name, member.middle_name, member.last_name, member.suffix_name]
    .filter(Boolean)
    .join(' ');
}

export function initials(member) {
  const parts = [member.first_name, member.middle_name, member.last_name, member.suffix_name].filter(
    (p) => p && p.length
  );

  if (parts.length === 0) return '?';

  return parts
    .slice(0, 2)
    .map((p) => p.charAt(0).toUpperCase())
    .join('');
}
