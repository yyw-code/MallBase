export function normalizeRichTextHtml(html) {
  if (!html) return ''

  return String(html)
    .replace(/<(p|div|section|span|table|tbody|thead|tr|td|th)\b([^>]*)>/gi, (match, tag, attrs) => {
      const cleanedAttrs = normalizeRichTextAttrs(attrs)
      return `<${tag}${cleanedAttrs}>`
    })
    .replace(/<img\b([^>]*)>/gi, (match, attrs) => {
      const cleanedAttrs = normalizeRichTextAttrs(attrs)
      return `<img${cleanedAttrs} style="max-width:100%;width:100%;height:auto;display:block;box-sizing:border-box;" />`
    })
}

function normalizeRichTextAttrs(attrs = '') {
  return String(attrs)
    .replace(/\/\s*$/g, '')
    .replace(/\s(width|height)=["'][^"']*["']/gi, '')
    .replace(/\s(width|height)=[^\s>]*/gi, '')
    .replace(/\sstyle=(["'])(.*?)\1/gi, (match, quote, style) => {
      const rules = style
        .split(';')
        .map((rule) => rule.trim())
        .filter(Boolean)
        .filter((rule) => !/^(width|min-width|max-width|height|min-height|max-height|left|right|margin-left|margin-right|position|transform)\s*:/i.test(rule))

      if (rules.length === 0) return ''
      return ` style="${rules.join(';')}"`
    })
}
