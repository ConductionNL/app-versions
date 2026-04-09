import type { AppVersion, VersionRangeInfo } from '../types'

export const parseVersionCore = (version: string): { major: number, minor: number, patch: number } => {
	const [core] = version.split('-', 2)
	const [rawMajor, rawMinor, rawPatch] = core.split('.')

	return {
		major: Number.parseInt(rawMajor || '0', 10) || 0,
		minor: Number.parseInt(rawMinor || '0', 10) || 0,
		patch: Number.parseInt(rawPatch || '0', 10) || 0,
	}
}

export const compareVersions = (left: string, right: string): number => {
	const [leftCore, leftPre = ''] = left.split('-', 2)
	const [rightCore, rightPre = ''] = right.split('-', 2)
	const leftParts = leftCore.split('.').map((part) => Number(part || '0'))
	const rightParts = rightCore.split('.').map((part) => Number(part || '0'))

	for (let index = 0; index < Math.max(leftParts.length, rightParts.length); index++) {
		const leftPart = leftParts[index] ?? 0
		const rightPart = rightParts[index] ?? 0

		if (leftPart > rightPart) {
			return 1
		}
		if (leftPart < rightPart) {
			return -1
		}
	}

	if (leftPre === rightPre) {
		return 0
	}
	if (!leftPre) {
		return 1
	}
	if (!rightPre) {
		return -1
	}

	const leftPreParts = leftPre.split('.')
	const rightPreParts = rightPre.split('.')
	for (let index = 0; index < Math.max(leftPreParts.length, rightPreParts.length); index++) {
		const leftPart = leftPreParts[index]
		const rightPart = rightPreParts[index]

		if (leftPart === undefined) {
			return -1
		}
		if (rightPart === undefined) {
			return 1
		}

		const leftNumeric = /^\d+$/.test(leftPart)
		const rightNumeric = /^\d+$/.test(rightPart)

		if (leftNumeric && rightNumeric) {
			const leftNum = Number(leftPart)
			const rightNum = Number(rightPart)
			if (leftNum > rightNum) {
				return 1
			}
			if (leftNum < rightNum) {
				return -1
			}
			continue
		}

		if (leftNumeric) {
			return -1
		}
		if (rightNumeric) {
			return 1
		}

		if (leftPart > rightPart) {
			return 1
		}
		if (leftPart < rightPart) {
			return -1
		}
	}

	return 0
}

export const getVersionRangeSummary = (from: string, to: string, versions: AppVersion[]): VersionRangeInfo | null => {
	if (!from || !to || from === to) {
		return null
	}

	const direction = compareVersions(to, from)
	const comparison = direction === 0 ? 0 : (direction > 0 ? 1 : -1)
	const low = comparison <= 0 ? to : from
	const high = comparison <= 0 ? from : to

	const inRange = versions.filter((entry) => {
		return compareVersions(entry.version, low) >= 0 && compareVersions(entry.version, high) <= 0
	})

	const majors = new Set<number>()
	const minors = new Set<string>()
	for (const entry of inRange) {
		const parsed = parseVersionCore(entry.version)
		majors.add(parsed.major)
		minors.add(`${parsed.major}.${parsed.minor}`)
	}

	const fromParsed = parseVersionCore(low)
	const toParsed = parseVersionCore(high)
	const patch = fromParsed.major === toParsed.major && fromParsed.minor === toParsed.minor
		? Math.abs(toParsed.patch - fromParsed.patch)
		: 0

	if (majors.size === 0) {
		const major = Math.abs(toParsed.major - fromParsed.major)
		const minor = comparison === 0
			? 0
			: (toParsed.major === fromParsed.major ? Math.abs(toParsed.minor - fromParsed.minor) : Math.abs(toParsed.minor - fromParsed.minor))

		return {
			major,
			minor,
			patch,
			direction: comparison > 0 ? 'upgrade' : 'degrade',
			from,
			to,
		}
	}

	return {
		major: Math.max(0, majors.size - 1),
		minor: Math.max(0, minors.size - 1),
		patch,
		direction: comparison > 0 ? 'upgrade' : 'degrade',
		from,
		to,
	}
}

export const versionRangeText = (summary: VersionRangeInfo | null): string => {
	if (!summary) {
		return ''
	}

	if (summary.major === 0 && summary.minor === 0 && summary.patch > 0) {
		return `${summary.direction === 'upgrade' ? 'Upgrade' : 'Downgrade'} stays within major/minor and changes ${summary.patch} patch version step${summary.patch === 1 ? '' : 's'}.`
	}

	return `${summary.direction === 'upgrade' ? 'Upgrade' : 'Downgrade'} crosses ${summary.major} major and ${summary.minor} minor version step${summary.minor === 1 ? '' : 's'}.`
}
