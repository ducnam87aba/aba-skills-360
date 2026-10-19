/** @type {import('next').NextConfig} */
const nextConfig = {
  transpilePackages: ['@aba/ui', '@aba/api-client', '@aba/types', '@aba/validation'],
  reactStrictMode: true,
};

export default nextConfig;
