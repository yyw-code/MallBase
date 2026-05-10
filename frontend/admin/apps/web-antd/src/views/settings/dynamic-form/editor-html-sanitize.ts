import DOMPurify from 'dompurify';

const allowedTags = [
  'a',
  'blockquote',
  'br',
  'code',
  'div',
  'em',
  'h1',
  'h2',
  'h3',
  'h4',
  'h5',
  'h6',
  'img',
  'li',
  'ol',
  'p',
  'pre',
  'span',
  'strong',
  'table',
  'tbody',
  'td',
  'tfoot',
  'th',
  'thead',
  'tr',
  'u',
  'ul',
];

const allowedAttrs = [
  'alt',
  'colspan',
  'height',
  'href',
  'rel',
  'rowspan',
  'src',
  'target',
  'title',
  'width',
];

export function sanitizeEditorHtml(html: unknown): string {
  if (typeof html !== 'string' || html === '') {
    return '';
  }

  const withoutExecutableTags = html.replaceAll(
    /<\s*(script|iframe|object|embed|style)\b[^>]*>[\s\S]*?<\s*\/\s*\1\s*>/gi,
    '',
  );

  return DOMPurify.sanitize(withoutExecutableTags, {
    ALLOWED_ATTR: allowedAttrs,
    ALLOWED_TAGS: allowedTags,
    ALLOW_DATA_ATTR: false,
  });
}
