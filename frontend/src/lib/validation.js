export function validationMessage(messages, t) {
  return messages.map((message) => {
    if (message === 'This language version already exists.') return t('This language version already exists.')
    if (message.endsWith(' field is required.')) return t('This field is required.')
    if (message.endsWith(' field must be a string.')) return t('Enter text in this field.')
    const maximum = message.match(/ field must not be greater than (\d+) characters\.$/)
    if (maximum) return t('Use no more than {{max}} characters.', { max: maximum[1] })
    if (message === 'The selected content language is invalid.') return t('Select a supported content language.')
    if (message === 'Secondary keywords must differ from the primary keyword.') return t('Secondary keywords must differ from the primary keyword.')
    if (/ field has a duplicate value\.$/.test(message)) return t('Remove duplicate keywords or URLs.')
    if (/ field must be a valid URL\.$/.test(message)) return t('Enter a valid HTTP or HTTPS URL.')
    const items = message.match(/ field must not have more than (\d+) items\.$/)
    if (items) return t('Use no more than {{max}} items.', { max: items[1] })
    return t('Invalid value.')
  }).join(' ')
}
