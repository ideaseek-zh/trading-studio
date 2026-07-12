from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    app_name: str = "Trading Studio Intelligence"
    app_env: str = "local"
    api_port: int = 8080

    mysql_host: str = "127.0.0.1"
    mysql_port: int = 3306
    mysql_database: str = "ideaseek_trading_studio"
    mysql_user: str = "root"
    mysql_password: str = ""

    redis_url: str = "redis://127.0.0.1:6379/0"
    qdrant_url: str = "http://127.0.0.1:6333"
    internal_service_token: str = "trading-studio-internal-token"
    market_provider: str = "akshare"
    news_provider: str = "akshare"

    model_config = SettingsConfigDict(
        env_file=".env",
        extra="ignore",
    )

    @property
    def sqlalchemy_database_uri(self) -> str:
        password_part = f":{self.mysql_password}" if self.mysql_password else ""
        return (
            f"mysql+pymysql://{self.mysql_user}{password_part}"
            f"@{self.mysql_host}:{self.mysql_port}/{self.mysql_database}"
            "?charset=utf8mb4"
        )


settings = Settings()
