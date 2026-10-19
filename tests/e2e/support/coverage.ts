import MCR from 'monocart-coverage-reports';

// JavaScript coverage of the anchoring library (tests/e2e/specs/anchoring.spec.ts): every worker adds V8 coverage to
// the cache in JS_COVERAGE_DIR, and the global teardown writes the report there (index.html and a console summary).
export const ANCHORING_URL = 'http://anchoring.test/anchoring.js';

const options: MCR.CoverageReportOptions = {
	name: 'ArticleNotes anchoring library',
	outputDir: process.env.JS_COVERAGE_DIR ?? '/tmp/js-coverage',
	reports: ['v8', 'console-summary'],
	entryFilter: (entry) => entry.url === ANCHORING_URL,
	sourcePath: () => 'xExtension-ArticleNotes/static/anchoring.js',
};

export function coverageReport(): MCR.CoverageReport {
	return MCR(options);
}
