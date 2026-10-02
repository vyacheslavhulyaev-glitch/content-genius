// UX defaults mirror config/generation.php; server-side validation is authoritative.
export const generationLimits = {
  title: 180, topic: 1000, tone: 80, primary_keyword: 120,
  secondary_keywords: 8, secondary_keyword: 80, meta_title: 60, meta_description: 160,
  links: 5, anchor: 80, url: 1024, min_words: 250, max_words: 1500, default_words: 800,
}

export function articleWords(value = '') {
  const legacy = { short: 250, medium: 800, long: 1200 }
  const text = value.toLowerCase().trim()
  if (legacy[text]) return legacy[text]
  return text.match(/^(?:(?:approximately|about|приблизно|близько|etwa|ca\.?)\s+)?(\d{1,5})(?:\s*(?:words?|слів|слова|wörter|woerter))?$/iu)?.[1] ?? ''
}
