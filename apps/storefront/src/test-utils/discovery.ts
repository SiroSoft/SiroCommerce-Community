export function latestRequest<T>() {
  let sequence = 0;
  let active: AbortController | undefined;
  return async (work: (signal: AbortSignal) => Promise<T>): Promise<T | undefined> => {
    active?.abort();
    const controller = new AbortController();
    active = controller;
    const current = ++sequence;
    try {
      const value = await work(controller.signal);
      return current === sequence && !controller.signal.aborted ? value : undefined;
    } finally {
      if (current === sequence) active = undefined;
    }
  };
}
