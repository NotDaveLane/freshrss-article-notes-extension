import { coverageReport } from './coverage';

export default async function globalSetup(): Promise<void> {
	coverageReport().cleanCache();
}
