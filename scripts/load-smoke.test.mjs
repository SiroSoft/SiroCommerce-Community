import test from 'node:test';
import assert from 'node:assert/strict';
import { computeRampConcurrency } from './load-smoke.mjs';

test('ramp starts at the initial concurrency', () => {
  assert.equal(computeRampConcurrency(0, 300000, 30), 5);
});

test('ramp increases linearly to the peak', () => {
  assert.equal(computeRampConcurrency(5000, 10000, 30), 18);
  assert.equal(computeRampConcurrency(10000, 10000, 30), 30);
  assert.equal(computeRampConcurrency(15000, 10000, 30), 30);
});

test('ramp clamps to the peak when the peak is below the start', () => {
  assert.equal(computeRampConcurrency(0, 10000, 3), 3);
  assert.equal(computeRampConcurrency(10000, 10000, 3), 3);
});

test('ramp is monotonic and never exceeds the peak', () => {
  let previous = 0;
  for (let elapsed = 0; elapsed <= 60000; elapsed += 1000) {
    const value = computeRampConcurrency(elapsed, 60000, 30);
    assert.ok(value >= previous);
    assert.ok(value <= 30);
    previous = value;
  }
});
