// Runs the scenarios in order against one target and reports each. A
// scenario that fails stops the run: the later ones build on its answers.
import type { Violation } from '../../tests/fake-pixonaut/src/validate';
import { type Found, SCENARIOS, type Scenario } from './scenarios';
import { Session, type Target } from './session';

export type ScenarioResult = {
	name: string;
	/** Why it could not finish; absent when it did. */
	error?: string;
	violations: Violation[];
};

export type Report = {
	target: Target['name'];
	results: ScenarioResult[];
	/** Consumed operations no scenario sent. */
	uncalled: string[];
	passed: boolean;
};

export async function runScenarios(
	target: Target,
	scenarios: Scenario[] = SCENARIOS,
): Promise<Report> {
	const session = new Session(target);
	const found: Found = {};
	const results: ScenarioResult[] = [];
	for (const scenario of scenarios) {
		const before = session.violations.length;
		let error: string | undefined;
		try {
			await scenario.run(session, found);
		} catch (thrown) {
			error = thrown instanceof Error ? thrown.message : String(thrown);
		}
		results.push({
			name: scenario.name,
			error,
			violations: session.violations.slice(before),
		});
		if (error !== undefined) {
			break;
		}
	}
	const uncalled = session.contract.consumed.operations.filter(
		(operation) => !session.called.has(operation),
	);
	return {
		target: target.name,
		results,
		uncalled,
		passed:
			results.length === scenarios.length &&
			uncalled.length === 0 &&
			results.every(
				(result) =>
					result.error === undefined && result.violations.length === 0,
			),
	};
}

/** The report as lines to print. */
export function describeReport(report: Report): string[] {
	const lines: string[] = [];
	for (const result of report.results) {
		const ok = result.error === undefined && result.violations.length === 0;
		lines.push(`${ok ? 'ok  ' : 'FAIL'} ${result.name}`);
		if (result.error !== undefined) {
			lines.push(`     ${result.error}`);
		}
		for (const violation of result.violations) {
			lines.push(
				`     ${violation.operation} ${violation.direction} ${violation.at}: ${violation.message}`,
			);
		}
	}
	if (report.uncalled.length > 0) {
		lines.push(`Not sent by any scenario: ${report.uncalled.join(', ')}`);
	}
	return lines;
}
