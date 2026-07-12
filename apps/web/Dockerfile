FROM node:24.11.1-alpine

WORKDIR /app
COPY . .

RUN npm install

EXPOSE 3000
CMD ["sh", "-lc", "npm run dev -- --host 0.0.0.0 --port 3000"]
