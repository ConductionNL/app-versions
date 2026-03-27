const debugValueToString = (value: unknown): string => {
	if (value === null) {
		return 'null'
	}

	if (typeof value === 'string') {
		return value
	}

	if (typeof value === 'number' || typeof value === 'boolean') {
		return String(value)
	}

	if (typeof value === 'bigint') {
		return value.toString()
	}

	return JSON.stringify(value)
}

const formatDebugLines = (value: unknown, depth = 0): string[] => {
	const indent = ' '.repeat(depth * 2)
	const lines: string[] = []

	if (value === null || value === undefined) {
		lines.push(`${indent}—`)
		return lines
	}

	if (Array.isArray(value)) {
		if (value.length === 0) {
			lines.push(`${indent}[]`)
			return lines
		}

		value.forEach((entry, index) => {
			if (entry === null || entry === undefined) {
				lines.push(`${indent}[${index}]: —`)
				return
			}

			if (typeof entry === 'object') {
				lines.push(`${indent}[${index}]:`)
				lines.push(...formatDebugLines(entry, depth + 1))
			} else {
				lines.push(`${indent}[${index}]: ${debugValueToString(entry)}`)
			}
		})

		return lines
	}

	if (typeof value === 'object') {
		const objectValue = value as Record<string, unknown>
		const keys = Object.keys(objectValue)
		if (keys.length === 0) {
			lines.push(`${indent}{}`)
			return lines
		}

		for (const key of keys) {
			const nested = objectValue[key]
			if (nested === null || nested === undefined) {
				lines.push(`${indent}${key}: —`)
				continue
			}

			if (typeof nested === 'object') {
				lines.push(`${indent}${key}:`)
				lines.push(...formatDebugLines(nested, depth + 1))
				continue
			}

			lines.push(`${indent}${key}: ${debugValueToString(nested)}`)
		}
		return lines
	}

	lines.push(`${indent}${debugValueToString(value)}`)
	return lines
}

export const debugHasData = (value: unknown): boolean => {
	if (value === null || value === undefined) {
		return false
	}

	if (typeof value === 'string') {
		return value.trim() !== ''
	}

	return true
}

export const debugToTextLines = (value: unknown): string[] => {
	const lines = formatDebugLines(value)
	if (lines.length === 0) {
		return ['—']
	}

	return lines
}
