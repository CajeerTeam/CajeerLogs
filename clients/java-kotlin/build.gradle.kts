import org.gradle.api.publish.maven.MavenPublication

plugins {
    kotlin("jvm") version "2.0.0"
    `maven-publish`
}

group = "ru.cajeer.logs"
version = "0.8.4"

repositories { mavenCentral() }
kotlin {
    jvmToolchain(17)
    sourceSets.named("test") { kotlin.srcDir("../../tests/sdk") }
}
sourceSets.named("test") { java.srcDir("../../tests/sdk") }
java { withSourcesJar() }

publishing {
    publications {
        create<MavenPublication>("javaKotlin") {
            from(components["java"])
            artifactId = "cajeer-logs-kotlin"
            pom {
                name.set("CajeerLogs Kotlin/JVM SDK")
                description.set("Bounded background logging, HMAC and verified ingestion for JVM 17+")
                url.set("https://github.com/DeepLayerTeam/CajeerLogs")
                licenses { license { name.set("Apache License, Version 2.0"); url.set("https://www.apache.org/licenses/LICENSE-2.0") } }
            }
        }
    }
}

tasks.register<JavaExec>("wireTest") {
    dependsOn(tasks.named("testClasses"))
    classpath = sourceSets["test"].runtimeClasspath
    mainClass.set("WireTestKt")
    doFirst { args(providers.gradleProperty("wireUrl").get(), providers.gradleProperty("wireToken").get()) }
}
tasks.register<JavaExec>("asyncWireTest") {
    dependsOn(tasks.named("testClasses"))
    classpath = sourceSets["test"].runtimeClasspath
    mainClass.set("AsyncWireTestKt")
    doFirst { args(providers.gradleProperty("wireUrl").get(), providers.gradleProperty("wireToken").get()) }
}
tasks.register<JavaExec>("queueWireTest") {
    dependsOn(tasks.named("testClasses"))
    classpath = sourceSets["test"].runtimeClasspath
    mainClass.set("KotlinQueueTestKt")
    doFirst { args(providers.gradleProperty("wireUrl").get(), providers.gradleProperty("wireToken").get()) }
}
tasks.register<JavaExec>("javaWireTest") {
    dependsOn(tasks.named("testClasses"))
    classpath = sourceSets["test"].runtimeClasspath
    mainClass.set("KotlinQueueJavaTest")
    doFirst { args(providers.gradleProperty("wireUrl").get(), providers.gradleProperty("wireToken").get()) }
}
