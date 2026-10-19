import { coverageReport } from './coverage';

export default async function globalTeardown(): Promise<void> {
	const report = coverageReport();
	if (report.hasCache()) {	// only when the anchoring spec ran
		await report.generate();
	}
}
