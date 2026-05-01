plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

fun stringBuildValue(name: String, fallback: String = ""): String {
    val env = System.getenv(name)?.trim().orEmpty()
    if (env.isNotEmpty()) return env
    val prop = (project.findProperty(name) as? String)?.trim().orEmpty()
    return if (prop.isNotEmpty()) prop else fallback
}

android {
    namespace = "pro.sistemax.main"
    compileSdk = 34

    defaultConfig {
        applicationId = "pro.sistemax.main"
        minSdk = 26
        targetSdk = 34
        versionCode = 18
        versionName = "0.3.6"
        buildConfigField("String", "START_URL", "\"${stringBuildValue("SISTEMAX_ANDROID_START_URL", "https://sistemax.pro/public/login.php")}\"")
        buildConfigField("String", "SX_FIREBASE_APP_ID", "\"${stringBuildValue("SISTEMAX_FIREBASE_APP_ID")}\"")
        buildConfigField("String", "SX_FIREBASE_API_KEY", "\"${stringBuildValue("SISTEMAX_FIREBASE_API_KEY")}\"")
        buildConfigField("String", "SX_FIREBASE_PROJECT_ID", "\"${stringBuildValue("SISTEMAX_FIREBASE_PROJECT_ID")}\"")
        buildConfigField("String", "SX_FIREBASE_SENDER_ID", "\"${stringBuildValue("SISTEMAX_FIREBASE_SENDER_ID")}\"")
        buildConfigField("String", "SX_FIREBASE_STORAGE_BUCKET", "\"${stringBuildValue("SISTEMAX_FIREBASE_STORAGE_BUCKET")}\"")
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }

    buildFeatures {
        viewBinding = true
        buildConfig = true
    }
}

dependencies {
    implementation(platform("com.google.firebase:firebase-bom:33.1.2"))
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.swiperefreshlayout:swiperefreshlayout:1.1.0")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.4")
    implementation("androidx.work:work-runtime-ktx:2.9.1")
    implementation("com.google.android.gms:play-services-location:21.3.0")
    implementation("com.google.firebase:firebase-messaging-ktx")
    implementation("com.dafruits:webrtc:123.0.0")
}
