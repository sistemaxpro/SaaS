import Foundation

enum APIClientError: LocalizedError {
    case invalidURL
    case invalidResponse
    case server(String)

    var errorDescription: String? {
        switch self {
        case .invalidURL:
            return "URL invalida"
        case .invalidResponse:
            return "Respuesta invalida del servidor"
        case .server(let message):
            return message
        }
    }
}

struct APIClient {
    func registerIOS(apiURL: String, bootstrapToken: String, deviceUUID: String, deviceName: String) async throws -> AgentRegistrationData {
        let payload: [String: Any] = [
            "bootstrap_token": bootstrapToken,
            "device_uuid": deviceUUID,
            "device_name": deviceName,
            "platform": "ios",
            "platform_version": UIDevice.current.systemVersion,
            "architecture": "arm64",
            "app_version": Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "0.1.0"
        ]
        return try await postJSON(
            apiURL: apiURL,
            action: "ios_register_bootstrap",
            bearer: nil,
            payload: payload,
            decode: AgentRegistrationData.self
        )
    }

    func registerPush(apiURL: String, agentToken: String, fcmToken: String) async throws -> PushRegistrationData {
        let payload: [String: Any] = [
            "fcm_token": fcmToken,
            "platform": "ios",
            "app_version": Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "0.1.0"
        ]
        return try await postJSON(
            apiURL: apiURL,
            action: "ios_push_register",
            bearer: agentToken,
            payload: payload,
            decode: PushRegistrationData.self
        )
    }

    func fetchStatus(apiURL: String, agentToken: String) async throws -> DeviceStatusData {
        guard var components = URLComponents(string: apiURL) else {
            throw APIClientError.invalidURL
        }
        components.queryItems = [URLQueryItem(name: "action", value: "ios_status")]
        guard let url = components.url else {
            throw APIClientError.invalidURL
        }

        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.setValue("Bearer \(agentToken)", forHTTPHeaderField: "Authorization")
        let (data, response) = try await URLSession.shared.data(for: request)
        guard let http = response as? HTTPURLResponse, (200..<300).contains(http.statusCode) else {
            throw APIClientError.invalidResponse
        }
        let envelope = try JSONDecoder().decode(TokenEnvelope<DeviceStatusData>.self, from: data)
        guard envelope.ok, let data = envelope.data else {
            throw APIClientError.server(envelope.error ?? "No se pudo consultar el estado")
        }
        return data
    }

    private func postJSON<T: Decodable>(
        apiURL: String,
        action: String,
        bearer: String?,
        payload: [String: Any],
        decode: T.Type
    ) async throws -> T {
        guard var components = URLComponents(string: apiURL) else {
            throw APIClientError.invalidURL
        }
        components.queryItems = [URLQueryItem(name: "action", value: action)]
        guard let url = components.url else {
            throw APIClientError.invalidURL
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json; charset=utf-8", forHTTPHeaderField: "Content-Type")
        if let bearer, !bearer.isEmpty {
            request.setValue("Bearer \(bearer)", forHTTPHeaderField: "Authorization")
        }
        request.httpBody = try JSONSerialization.data(withJSONObject: payload, options: [])

        let (data, response) = try await URLSession.shared.data(for: request)
        guard let http = response as? HTTPURLResponse, (200..<300).contains(http.statusCode) else {
            throw APIClientError.invalidResponse
        }
        let envelope = try JSONDecoder().decode(TokenEnvelope<T>.self, from: data)
        guard envelope.ok, let result = envelope.data else {
            throw APIClientError.server(envelope.error ?? "Operacion rechazada")
        }
        return result
    }
}
