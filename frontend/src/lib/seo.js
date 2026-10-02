import { generationLimits } from './generationLimits.js'

export const maxLinks = generationLimits.links

export function seoFormFields(content = {}) {
  return {
    primary_keyword: content.primary_keyword || content.title || '',
    secondary_keywords: (content.secondary_keywords || []).join('\n'),
    meta_title: content.meta_title || '',
    meta_description: content.meta_description || '',
    links: (content.links || []).map(link => ({ ...link })),
  }
}

export function seoPayload(fields) {
  return {
    primary_keyword: fields.primary_keyword.trim() || null,
    secondary_keywords: fields.secondary_keywords.split(/\r?\n/).map(value => value.trim()).filter(Boolean),
    meta_title: fields.meta_title.trim() || null,
    meta_description: fields.meta_description.trim() || null,
    links: fields.links.map(link => ({ anchor: link.anchor.trim(), url: link.url.trim() })),
  }
}
