export const sRGBToLinear = (value: number) => {
  const v = value / 255;
  return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
};

export const linearTosRGB = (value: number) => {
  const v = Math.max(0, Math.min(1, value));
  const a = 1.055,
    b = 0.055,
    gamma = 1 / 2.4;
  return Math.trunc(
    (v <= 0.0031308 ? v * 12.92 : a * Math.pow(v, gamma) - b) * 255 + 0.5,
  );
};