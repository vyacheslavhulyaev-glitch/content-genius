export function selectedGroupContents(contents, selectedContentIds) {
  const groups = new Map()
  for (const content of contents) {
    if (!groups.has(content.content_group_id)) groups.set(content.content_group_id, [])
    groups.get(content.content_group_id).push(content)
  }
  // Group order must not change when a newer localized draft is added.
  return [...groups.entries()].sort(([first], [second]) => second - first)
    .map(([groupId, versions]) => versions.find((content) => content.id === selectedContentIds[groupId])
      ?? versions.find((content) => content.content_language === content.primary_language)
      ?? versions[0])
}

export function mergeContentSnapshot(current, incoming, revisions, startedRevisions) {
  const changed = (content) => (revisions.get(content.content_group_id) ?? 0) !== (startedRevisions.get(content.content_group_id) ?? 0)
  return [...incoming.filter((content) => !changed(content)), ...current.filter(changed)]
    .sort(newestFirst)
}

export function mergeContentMutation(contents, source, result) {
  const remaining = contents.filter((content) => content.id !== (result?.id ?? source.id))
  if (result) remaining.push(result)
  const versions = remaining.filter((content) => content.content_group_id === source.content_group_id)
    .sort((first, second) => first.id - second.id)
  const translations = result?.translations ?? versions.map((version) => ({
    id: version.id,
    content_language: version.content_language,
    has_generated_content: Boolean(version.generated_content?.trim()),
    is_generation_stale: version.is_generation_stale,
  }))
  const primaryLanguage = result?.primary_language ?? (
    versions.some((content) => content.content_language === source.primary_language)
      ? source.primary_language : versions[0]?.content_language
  )

  return remaining.map((content) => content.content_group_id === source.content_group_id
    ? { ...content, translations, primary_language: primaryLanguage }
    : content).sort(newestFirst)
}

function newestFirst(first, second) {
  return new Date(second.created_at) - new Date(first.created_at) || second.id - first.id
}

export function languageVersionStatus(version, generatingContentIds) {
  if (!version) return 'missing'
  if (generatingContentIds?.has(version.id)) return 'pending'
  const hasGeneratedContent = version.has_generated_content ?? Boolean(version.generated_content?.trim())
  if (!hasGeneratedContent) return 'draft'
  return version.is_generation_stale ? 'stale' : 'generated'
}
