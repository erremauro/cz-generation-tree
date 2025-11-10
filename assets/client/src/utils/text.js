// assets/client/src/utils/text.js
let __czgt_textarea = null;

/** Decode HTML entities like &amp; &#8221; &quot; etc. */
export function decodeEntities(str) {
  if (str == null) return '';
  const s = String(str);
  if (!s) return '';
  if (!__czgt_textarea) __czgt_textarea = document.createElement('textarea');
  __czgt_textarea.innerHTML = s;
  return __czgt_textarea.value;
}
